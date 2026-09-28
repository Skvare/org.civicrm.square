<?php

require_once __DIR__ . '/SquareLedgerTestCase.php';

/**
 * Recording Square refunds (refund.created / refund.updated) in CiviCRM.
 */
class CRM_Core_Payment_Square_RefundTest extends CRM_Core_Payment_Square_SquareLedgerTestCase {

  private const REFUND_ID = 'REFUND-1';

  /**
   * The first installment (19.00, Square payment FIRST) has been paid.
   */
  protected function setUp(): void {
    parent::setUp();
    $this->payFirstInstallment();
  }

  public function testPendingRefundRecordsNothing(): void {
    $this->deliver($this->refundEvent('refund.created', 'PENDING', 1900));

    $this->assertSame([], $this->refunds());
    $this->assertSame('Completed', $this->processor->contributions[self::SIGNUP_CONTRIBUTION_ID]['status']);
  }

  /**
   * A full refund reverses the refunded payment, as Payment.cancel does.
   */
  public function testFullRefundCancelsTheRefundedPayment(): void {
    $this->deliver($this->refundEvent('refund.updated', 'COMPLETED', 1900));

    $refunds = $this->refunds();
    $this->assertCount(1, $refunds);
    $refund = reset($refunds);
    $this->assertSame(-19.00, $refund['total_amount']);
    $this->assertSame(self::REFUND_ID, $refund['trxn_id']);
    $this->assertSame(self::SIGNUP_CONTRIBUTION_ID, $refund['contribution_id']);
    $this->assertSame($this->originalPaymentId(), $refund['cancelled_payment_id']);
    $this->assertSame(self::PROCESSOR_ID, $refund['payment_processor_id']);
    $this->assertSame('Refunded', $this->processor->contributions[self::SIGNUP_CONTRIBUTION_ID]['status']);
  }

  /**
   * A partial one is not linked: that would reverse the whole payment's allocations.
   */
  public function testPartialRefundIsNotLinkedToTheRefundedPayment(): void {
    $this->deliver($this->refundEvent('refund.updated', 'COMPLETED', 500));

    $refunds = $this->refunds();
    $this->assertCount(1, $refunds);
    $refund = reset($refunds);
    $this->assertSame(-5.00, $refund['total_amount']);
    $this->assertArrayNotHasKey('cancelled_payment_id', $refund);
    $this->assertSame('Completed', $this->processor->contributions[self::SIGNUP_CONTRIBUTION_ID]['status']);
  }

  /**
   * Square can report the same completed refund in both events.
   */
  public function testRefundReportedTwiceIsRecordedOnce(): void {
    $this->deliver($this->refundEvent('refund.created', 'COMPLETED', 1900));
    $this->deliver($this->refundEvent('refund.updated', 'COMPLETED', 1900));

    $this->assertCount(1, $this->refunds());
  }

  /**
   * Staff already recorded it when refunding from CiviCRM (with Square's refund ID).
   */
  public function testRefundAlreadyRecordedFromCiviCrmIsSkipped(): void {
    $this->processor->payments[800] = [
      'contribution_id' => self::SIGNUP_CONTRIBUTION_ID,
      'trxn_id' => self::REFUND_ID,
      'total_amount' => -19.00,
      'payment_processor_id' => self::PROCESSOR_ID,
    ];

    $this->deliver($this->refundEvent('refund.updated', 'COMPLETED', 1900));

    $this->assertCount(1, $this->refunds());
  }

  /**
   * A refund of a payment no contribution here records is ignored.
   */
  public function testRefundOfAnUnknownPaymentIsIgnored(): void {
    $event = $this->refundEvent('refund.updated', 'COMPLETED', 1900);
    $event['data']['object']['refund']['payment_id'] = 'SOMEONE-ELSES-PAYMENT';

    $this->deliver($event);

    $this->assertSame([], $this->refunds());
  }

  /**
   * Another Square processor's webhook cannot refund this processor's payment.
   */
  public function testRefundForAnotherProcessorTouchesNothing(): void {
    $other = $this->ledgerProcessor(['id' => 43]);
    $other->contributions = $this->processor->contributions;
    // Recorded by processor 42, not 43.
    $other->payments = $this->processor->payments;

    $this->deliverTo($other, $this->refundEvent('refund.updated', 'COMPLETED', 1900));

    $this->assertSame($this->processor->payments, $other->payments, 'No refund recorded against a payment of processor 42.');
  }

  /**
   * A payment recorded before payments carried a processor is still refunded.
   */
  public function testRefundOfAPaymentRecordedWithoutAProcessor(): void {
    $this->processor->contributions[300] = [
      'id' => 300,
      'contribution_recur_id' => NULL,
      'status' => 'Completed',
      'total_amount' => 25.00,
      'currency' => 'USD',
      'trxn_id' => 'LEGACY-PAYMENT',
      'invoice_id' => NULL,
    ];
    $this->processor->payments[850] = [
      'contribution_id' => 300,
      'trxn_id' => 'LEGACY-PAYMENT',
      'total_amount' => 25.00,
      'payment_processor_id' => NULL,
    ];
    $event = $this->refundEvent('refund.updated', 'COMPLETED', 2500);
    $event['data']['object']['refund']['payment_id'] = 'LEGACY-PAYMENT';

    $this->deliver($event);

    $refunds = $this->refunds();
    $this->assertCount(1, $refunds);
    $refund = reset($refunds);
    $this->assertSame(300, $refund['contribution_id']);
    $this->assertSame(850, $refund['cancelled_payment_id']);
  }

  /**
   * Refund rows (negative payments) in the ledger.
   *
   * @return array
   */
  private function refunds(): array {
    return array_filter($this->processor->payments, fn (array $payment) => $payment['total_amount'] < 0);
  }

  /**
   * ID of the payment recorded for the first installment.
   *
   * @return int
   */
  private function originalPaymentId(): int {
    foreach ($this->processor->payments as $id => $payment) {
      if ($payment['trxn_id'] === self::FIRST['payment_id']) {
        return $id;
      }
    }
    $this->fail('The first installment was not recorded.');
  }

  /**
   * A refund webhook for the first installment's payment.
   *
   * @param string $type
   * @param string $status
   * @param int $amountCents
   *
   * @return array
   */
  private function refundEvent(string $type, string $status, int $amountCents): array {
    $now = gmdate('Y-m-d\TH:i:s\Z');
    return [
      'type' => $type,
      'event_id' => bin2hex(random_bytes(8)),
      'data' => [
        'type' => 'refund',
        'object' => [
          'refund' => [
            'id' => self::REFUND_ID,
            'payment_id' => self::FIRST['payment_id'],
            'status' => $status,
            'amount_money' => ['amount' => $amountCents, 'currency' => 'USD'],
            'created_at' => $now,
            'updated_at' => $now,
          ],
        ],
      ],
    ];
  }

}
