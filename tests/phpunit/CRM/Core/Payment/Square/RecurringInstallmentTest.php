<?php

require_once __DIR__ . '/SquareLedgerTestCase.php';

use Square\SquareClient;
use Square\Payments\PaymentsClient;
use Square\Subscriptions\Requests\CreateSubscriptionRequest;
use Square\Subscriptions\SubscriptionsClient;
use Square\Types\CreateSubscriptionResponse;
use Square\Types\Subscription;

/**
 * Recording Square subscription installments against CiviCRM contributions.
 *
 * The first installment must complete the Pending contribution CiviCRM
 * created at checkout — never a replacement — whichever of Square's
 * webhooks report it, in whatever order, however often.
 */
class CRM_Core_Payment_Square_RecurringInstallmentTest extends CRM_Core_Payment_Square_SquareLedgerTestCase {

  /**
   * Test A: Square's subscription charges the first installment — nothing else does.
   */
  public function testDoRecurPaymentLeavesTheFirstChargeToSquare(): void {
    $payments = $this->createMock(PaymentsClient::class);
    $payments->expects($this->never())->method('create');
    /** @var \Square\Subscriptions\Requests\CreateSubscriptionRequest|null $captured */
    $captured = NULL;
    $subscriptions = $this->createMock(SubscriptionsClient::class);
    $subscriptions->method('create')->willReturnCallback(function (CreateSubscriptionRequest $request) use (&$captured) {
      $captured = $request;
      return new CreateSubscriptionResponse(['subscription' => new Subscription(['id' => 'NEW-SUBSCRIPTION'])]);
    });
    $client = new SquareClient(token: 'test-token', options: ['baseUrl' => 'https://example.invalid']);
    $client->payments = $payments;
    $client->subscriptions = $subscriptions;
    $processor = new CRM_Core_Payment_Square_FakeLedgerProcessor($this->processorConfig(['id' => self::PROCESSOR_ID]), $client);
    $this->seedCheckout($processor);
    $processor->recurs[self::RECUR_ID]['processor_id'] = NULL;

    $params = [
      'square_payment_token' => 'cnon:card-nonce',
      'amount' => '19.00',
      'currency' => 'USD',
      'is_recur' => 1,
      'contributionRecurID' => self::RECUR_ID,
      'contributionID' => self::SIGNUP_CONTRIBUTION_ID,
      'contactID' => 7,
      'frequency_unit' => 'day',
      'frequency_interval' => 1,
    ];
    $result = $processor->doPayment($params);

    $this->assertNotNull($captured);
    $this->assertNull($captured->getStartDate(), 'No start date: Square starts the subscription, and bills its first invoice, immediately.');
    $this->assertSame('ccof:card-on-file', $captured->getCardId());
    $this->assertSame('PLAN-VARIATION-1', $captured->getPlanVariationId());

    $this->assertSame('Pending', $result['payment_status']);
    $this->assertSame(2, $result['payment_status_id']);
    $this->assertNull($result['trxn_id'], 'The subscription ID is not a payment ID and must not become the contribution trxn_id.');
    $this->assertSame('NEW-SUBSCRIPTION', $processor->recurs[self::RECUR_ID]['processor_id']);
    $this->assertSame(2, $processor->recurs[self::RECUR_ID]['contribution_status_id'], 'The series stays Pending until its first payment is recorded.');
  }

  /**
   * Test A: invoice.payment_made completes the checkout's contribution itself.
   */
  public function testFirstInstallmentCompletesTheCheckoutContribution(): void {
    $this->payFirstInstallment();

    $this->assertFirstInstallmentRecordedOnce();
    $payment = reset($this->processor->payments);
    $this->assertSame(19.00, $payment['total_amount']);
    $this->assertSame(0, $this->invoiceSearches, 'invoice.payment_made names its subscription; no invoice search is needed.');
  }

  /**
   * Test A, as the recorded sandbox webhooks deliver it: no invoice.payment_made at all.
   */
  public function testFirstInstallmentIsRecordedFromPaymentUpdatedAlone(): void {
    $this->squareBills(self::FIRST);

    $this->deliver($this->invoiceCreatedEvent(self::FIRST));
    $this->deliver($this->paymentUpdatedEvent(self::FIRST));

    $this->assertFirstInstallmentRecordedOnce();
    $payment = reset($this->processor->payments);
    $this->assertSame(0.93, $payment['fee_amount']);
  }

  /**
   * Checkouts from before this fix stored the subscription ID as the contribution's trxn_id.
   */
  public function testCheckoutContributionCarryingTheSubscriptionIdIsStillCompleted(): void {
    $this->processor->contributions[self::SIGNUP_CONTRIBUTION_ID]['trxn_id'] = self::SUBSCRIPTION_ID;

    $this->payFirstInstallment();

    $this->assertFirstInstallmentRecordedOnce();
  }

  /**
   * Square reports the processing fee only in a later payment.updated.
   */
  public function testProcessingFeeReportedLaterIsRecorded(): void {
    $this->squareBills(self::FIRST);

    $this->deliver($this->paymentUpdatedEvent(self::FIRST, 'COMPLETED', 1900, NULL));
    $this->assertSame(0.0, (float) ($this->processor->contributions[self::SIGNUP_CONTRIBUTION_ID]['fee_amount'] ?? 0));
    $this->deliver($this->paymentUpdatedEvent(self::FIRST));

    $this->assertFirstInstallmentRecordedOnce();
    $this->assertSame(0.93, $this->processor->contributions[self::SIGNUP_CONTRIBUTION_ID]['fee_amount']);
  }

  /**
   * A checkout that already sent a receipt (as webform_civicrm does) is not receipted again.
   */
  public function testCheckoutAlreadyReceiptedIsNotReceiptedAgain(): void {
    $this->processor->contributions[self::SIGNUP_CONTRIBUTION_ID]['receipt_date'] = '2026-09-23 16:45:50';

    $this->payFirstInstallment();

    $this->assertFalse(reset($this->processor->payments)['send_receipt']);
  }

  /**
   * The recurring contribution's receipt setting is honoured.
   */
  public function testFirstInstallmentHonoursTheRecurReceiptSetting(): void {
    $this->processor->recurs[self::RECUR_ID]['is_email_receipt'] = 0;

    $this->payFirstInstallment();

    $this->assertFalse(reset($this->processor->payments)['send_receipt']);
  }

  /**
   * Test B: replays of either event record nothing further.
   */
  public function testReplaysRecordNothingFurther(): void {
    $this->payFirstInstallment();

    $this->deliver($this->invoicePaymentMadeEvent(self::FIRST));
    $this->deliver($this->paymentUpdatedEvent(self::FIRST));
    $this->deliver($this->paymentUpdatedEvent(self::FIRST));
    $this->deliver($this->invoicePaymentMadeEvent(self::FIRST));

    $this->assertFirstInstallmentRecordedOnce();
  }

  /**
   * Test B: a replay after the installment was refunded is still a no-op.
   *
   * The recorded payment, not the contribution's current status, is what
   * says the Square payment was already processed.
   */
  public function testReplayAfterARefundRecordsNothingFurther(): void {
    $this->payFirstInstallment();
    $this->processor->contributions[self::SIGNUP_CONTRIBUTION_ID]['status'] = 'Refunded';

    $this->deliver($this->paymentUpdatedEvent(self::FIRST));
    $this->deliver($this->invoicePaymentMadeEvent(self::FIRST));

    $this->assertCount(1, $this->seriesContributions());
    $this->assertCount(1, $this->processor->payments);
    $this->assertSame('Refunded', $this->processor->contributions[self::SIGNUP_CONTRIBUTION_ID]['status']);
  }

  /**
   * Test C: webhook orderings that must not create a duplicate.
   *
   * @dataProvider webhookOrderProvider
   */
  public function testNoWebhookOrderCreatesADuplicate(array $order): void {
    $this->squareBills(self::FIRST);
    $events = [
      'invoice.created' => $this->invoiceCreatedEvent(self::FIRST),
      'payment.updated' => $this->paymentUpdatedEvent(self::FIRST),
      'invoice.payment_made' => $this->invoicePaymentMadeEvent(self::FIRST),
    ];

    foreach ($order as $type) {
      $this->deliver($events[$type]);
    }

    $this->assertFirstInstallmentRecordedOnce();
  }

  public static function webhookOrderProvider(): array {
    $orders = [
      ['invoice.created', 'payment.updated', 'invoice.payment_made'],
      ['payment.updated', 'invoice.created', 'invoice.payment_made'],
      ['invoice.payment_made', 'payment.updated', 'invoice.created'],
    ];
    $cases = [];
    foreach ($orders as $order) {
      $cases[implode(', ', $order)] = [$order];
    }
    return $cases;
  }

  /**
   * Invoice.created is never evidence of payment, and never creates a contribution.
   */
  public function testInvoiceCreatedRecordsNothing(): void {
    $this->deliver($this->invoiceCreatedEvent(self::FIRST));
    $this->deliver($this->invoiceCreatedEvent(self::SECOND));

    $this->assertCount(1, $this->seriesContributions());
    $this->assertSame('Pending', $this->processor->contributions[self::SIGNUP_CONTRIBUTION_ID]['status']);
    $this->assertSame([], $this->processor->payments);
  }

  /**
   * Test C: a payment arriving before checkout has linked the subscription is retried.
   */
  public function testPaymentBeforeTheSubscriptionIsLinkedIsRetriedNotDuplicated(): void {
    $this->squareBills(self::FIRST);
    $this->processor->recurs[self::RECUR_ID]['processor_id'] = NULL;

    try {
      $this->deliver($this->paymentUpdatedEvent(self::FIRST));
      $this->fail('Expected the event to be deferred for retry.');
    }
    catch (CRM_Core_Payment_SquareRetryableException $e) {
      $this->assertStringContainsString(self::SUBSCRIPTION_ID, $e->getMessage());
    }
    $this->assertCount(1, $this->processor->contributions);
    $this->assertSame([], $this->processor->payments);
    $this->assertSame([], $this->processor->createCalls);

    // The checkout finishes saving; the retry records the installment.
    $this->processor->recurs[self::RECUR_ID]['processor_id'] = self::SUBSCRIPTION_ID;
    $this->deliver($this->paymentUpdatedEvent(self::FIRST));

    $this->assertFirstInstallmentRecordedOnce();
  }

  /**
   * Test C: a recent card-on-file charge Square has no invoice for yet is retried, not imported.
   */
  public function testUnmatchedCardOnFileChargeIsRetriedNotImported(): void {
    $this->expectException(CRM_Core_Payment_SquareRetryableException::class);
    try {
      $this->deliver($this->paymentUpdatedEvent(self::FIRST));
    }
    finally {
      $this->assertSame([], $this->processor->createCalls, 'No standalone contribution may be created for it.');
      $this->assertSame([], $this->processor->payments);
    }
  }

  /**
   * A payment that is not complete records nothing, and costs no Square lookup.
   */
  public function testIncompletePaymentRecordsNothing(): void {
    $this->squareBills(self::FIRST);

    $this->deliver($this->paymentUpdatedEvent(self::FIRST, 'APPROVED'));

    $this->assertSame([], $this->processor->payments);
    $this->assertSame('Pending', $this->processor->contributions[self::SIGNUP_CONTRIBUTION_ID]['status']);
    $this->assertSame(0, $this->invoiceSearches);
  }

  /**
   * Test D: later installments are new contributions, via repeattransaction.
   *
   * @dataProvider secondInstallmentEventProvider
   */
  public function testSecondInstallmentCreatesAndCompletesANewContribution(string $eventType): void {
    $this->payFirstInstallment();
    $firstBefore = $this->processor->contributions[self::SIGNUP_CONTRIBUTION_ID];

    $this->squareBills(self::SECOND);
    $this->deliver($eventType === 'invoice.payment_made'
      ? $this->invoicePaymentMadeEvent(self::SECOND)
      : $this->paymentUpdatedEvent(self::SECOND));

    $this->assertSame($firstBefore, $this->processor->contributions[self::SIGNUP_CONTRIBUTION_ID], 'The first contribution is unchanged.');
    $this->assertCount(2, $this->seriesContributions());
    $this->assertCount(1, $this->processor->repeatCalls);
    $this->assertSame('Pending', $this->processor->repeatCalls[0]['contribution_status'], 'Created Pending, then completed by Payment.create.');
    $this->assertSame(self::SECOND['invoice_id'], $this->processor->repeatCalls[0]['invoice_id']);

    $second = $this->contributionWithTrxnId(self::SECOND['payment_id']);
    $this->assertNotSame(self::SIGNUP_CONTRIBUTION_ID, $second['id']);
    $this->assertSame(self::RECUR_ID, $second['contribution_recur_id']);
    $this->assertSame('Completed', $second['status']);
    $this->assertSame([self::FIRST['payment_id'], self::SECOND['payment_id']], array_column($this->processor->payments, 'trxn_id'));
    $this->assertSame($second['id'], end($this->processor->payments)['contribution_id']);
    $this->assertFalse(end($this->processor->payments)['send_receipt'], 'Later installments are not receipted, as before.');
    $this->assertSame([], $this->processor->createCalls, 'Installments never use a bare Contribution.create.');

    // Replays of the second installment record nothing further either.
    $this->deliver($this->invoicePaymentMadeEvent(self::SECOND));
    $this->deliver($this->paymentUpdatedEvent(self::SECOND));
    $this->assertCount(2, $this->seriesContributions());
    $this->assertCount(2, $this->processor->payments);
  }

  public static function secondInstallmentEventProvider(): array {
    return [
      'invoice.payment_made' => ['invoice.payment_made'],
      'payment.updated' => ['payment.updated'],
    ];
  }

  /**
   * Test E: an amount mismatch is raised, never written into CiviCRM.
   */
  public function testFirstInstallmentAmountMismatchIsNotRecorded(): void {
    $this->squareBills(self::FIRST, 2000);

    try {
      $this->deliver($this->invoicePaymentMadeEvent(self::FIRST, 2000));
      $this->fail('Expected a reconciliation error.');
    }
    catch (CRM_Core_Exception $e) {
      $this->assertNotInstanceOf(CRM_Core_Payment_SquareRetryableException::class, $e, 'A mismatch needs a person, not a retry.');
      $this->assertStringContainsString('manual reconciliation', $e->getMessage());
    }

    $contribution = $this->processor->contributions[self::SIGNUP_CONTRIBUTION_ID];
    $this->assertSame('Pending', $contribution['status']);
    $this->assertSame(19.00, $contribution['total_amount']);
    $this->assertSame('USD', $contribution['currency']);
    $this->assertNull($contribution['trxn_id'], 'Not claimed by the mismatched payment.');
    $this->assertSame([], $this->processor->payments);
    $this->assertContains('error', array_column(Civi::$logged, 0));
  }

  /**
   * Test E, for a later installment: no contribution is created for it.
   */
  public function testLaterInstallmentAmountMismatchCreatesNothing(): void {
    $this->payFirstInstallment();
    $this->squareBills(self::SECOND, 2000);

    try {
      $this->deliver($this->paymentUpdatedEvent(self::SECOND, 'COMPLETED', 2000));
      $this->fail('Expected a reconciliation error.');
    }
    catch (CRM_Core_Exception $e) {
      $this->assertStringContainsString('manual reconciliation', $e->getMessage());
    }
    $this->assertSame([], $this->processor->repeatCalls);
    $this->assertCount(1, $this->seriesContributions());
    $this->assertCount(1, $this->processor->payments);
  }

  /**
   * Test F: a webhook for one Square processor never touches another's records.
   *
   * @dataProvider otherProcessorProvider
   */
  public function testWebhookForAnotherProcessorTouchesNothing(array $configOverrides): void {
    $this->squareBills(self::FIRST);
    $other = $this->ledgerProcessor($configOverrides);
    $this->seedCheckout($other);
    // Its records belong to processor 42 (live); this one is someone else.
    $recursBefore = $other->recurs;
    $contributionsBefore = $other->contributions;

    try {
      $this->deliverTo($other, $this->invoicePaymentMadeEvent(self::FIRST));
    }
    catch (CRM_Core_Payment_SquareRetryableException $e) {
      // A recent event for an unknown subscription is retried; either way
      // nothing is recorded.
    }

    $this->assertSame($recursBefore, $other->recurs);
    $this->assertSame($contributionsBefore, $other->contributions);
    $this->assertSame([], $other->payments);
    $this->assertSame([], $other->repeatCalls);
  }

  public static function otherProcessorProvider(): array {
    return [
      'another processor' => [['id' => 43]],
      'the sandbox processor' => [['id' => 43, 'is_test' => TRUE]],
    ];
  }

  /**
   * A failed first charge leaves the checkout's contribution Pending — no Failed duplicate.
   */
  public function testFailedFirstChargeKeepsTheCheckoutContributionPending(): void {
    $this->deliver($this->invoicePaymentFailedEvent(self::FIRST));

    $this->assertCount(1, $this->seriesContributions());
    $this->assertSame('Pending', $this->processor->contributions[self::SIGNUP_CONTRIBUTION_ID]['status']);
    $this->assertSame([], $this->processor->repeatCalls);

    // Square collects the invoice later.
    $this->payFirstInstallment();
    $this->assertFirstInstallmentRecordedOnce();
  }

  /**
   * A later installment that failed, then was collected, is completed — not duplicated.
   */
  public function testFailedLaterInstallmentIsCompletedWhenCollected(): void {
    $this->payFirstInstallment();

    $this->deliver($this->invoicePaymentFailedEvent(self::SECOND));
    $this->assertCount(2, $this->seriesContributions());
    $failed = $this->seriesContributions()[1];
    $this->assertSame('Failed', $failed['status']);
    $this->assertSame(self::SECOND['invoice_id'], $failed['invoice_id']);

    $this->squareBills(self::SECOND);
    $this->deliver($this->invoicePaymentMadeEvent(self::SECOND));

    $this->assertCount(2, $this->seriesContributions());
    $this->assertCount(1, $this->processor->repeatCalls, 'Only the failed attempt was created; the payment completed it.');
    $this->assertSame('Completed', $this->processor->contributions[$failed['id']]['status']);
    $this->assertSame(self::SECOND['payment_id'], $this->processor->contributions[$failed['id']]['trxn_id']);
  }

  /**
   * A one-time payment Square cancels after checkout left it Pending is marked Failed.
   */
  public function testCanceledOneTimePaymentMarksItsPendingContributionFailed(): void {
    $this->processor->contributions[300] = [
      'id' => 300,
      'contribution_recur_id' => NULL,
      'status' => 'Pending',
      'total_amount' => 25.00,
      'currency' => 'USD',
      'trxn_id' => 'ONE-TIME-PAYMENT',
      'invoice_id' => 'civicrm-invoice-300',
    ];
    $event = $this->paymentUpdatedEvent(['payment_id' => 'ONE-TIME-PAYMENT', 'order_id' => 'ORDER-300'], 'CANCELED', 2500);
    $event['data']['object']['payment']['reference_id'] = 'civicrm-invoice-300';

    $this->deliver($event);

    $this->assertSame('Failed', $this->processor->contributions[300]['status']);
    $this->assertSame([], $this->processor->payments);
    $this->assertSame(0, $this->invoiceSearches);
  }

  /**
   * An invoice that is not fully paid yet records nothing.
   */
  public function testInvoicePaymentMadeForAPartiallyPaidInvoiceRecordsNothing(): void {
    $this->squareBills(self::FIRST);
    $event = $this->invoicePaymentMadeEvent(self::FIRST);
    $event['data']['object']['invoice']['status'] = 'PARTIALLY_PAID';

    $this->deliver($event);

    $this->assertSame([], $this->processor->payments);
    $this->assertSame('Pending', $this->processor->contributions[self::SIGNUP_CONTRIBUTION_ID]['status']);
  }

  /**
   * The first installment completed the checkout contribution, exactly once.
   */
  private function assertFirstInstallmentRecordedOnce(): void {
    $series = $this->seriesContributions();
    $this->assertCount(1, $series, 'No replacement contribution may be created for the first installment.');
    $this->assertSame(self::SIGNUP_CONTRIBUTION_ID, $series[0]['id']);
    $this->assertSame('Completed', $series[0]['status']);
    $this->assertSame(self::FIRST['payment_id'], $series[0]['trxn_id'], 'The trxn_id is the Square payment ID alone.');
    $this->assertSame(19.00, $series[0]['total_amount']);
    $this->assertSame([], $this->processor->repeatCalls);
    $this->assertSame([], $this->processor->createCalls);

    $this->assertCount(1, $this->processor->payments);
    $payment = reset($this->processor->payments);
    $this->assertSame(self::FIRST['payment_id'], $payment['trxn_id']);
    $this->assertSame(self::SIGNUP_CONTRIBUTION_ID, $payment['contribution_id']);
    $this->assertSame(self::PROCESSOR_ID, $payment['payment_processor_id']);
    $this->assertTrue($payment['send_receipt'], "Receipts follow the recurring contribution's is_email_receipt.");

    $this->assertSame(5, $this->processor->recurs[self::RECUR_ID]['contribution_status_id'], 'The series is In Progress once its first payment is recorded.');
  }

  /**
   * @param string $trxnId
   *
   * @return array
   */
  private function contributionWithTrxnId(string $trxnId): array {
    foreach ($this->processor->contributions as $row) {
      if ($row['trxn_id'] === $trxnId) {
        return $row;
      }
    }
    $this->fail("No contribution has trxn_id {$trxnId}.");
  }

}
