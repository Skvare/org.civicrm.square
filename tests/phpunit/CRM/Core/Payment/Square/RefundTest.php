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
    $lock = $this->getMockBuilder(stdClass::class)->addMethods(['isAcquired', 'release'])->getMock();
    $lock->method('isAcquired')->willReturn(TRUE);
    Civi::$lockManager = $this->getMockBuilder(stdClass::class)->addMethods(['acquire'])->getMock();
    Civi::$lockManager->method('acquire')->willReturn($lock);
  }

  protected function tearDown(): void {
    Civi::$lockManager = NULL;
    parent::tearDown();
  }

  public function testPendingRefundRecordsNothing(): void {
    $this->deliver($this->refundEvent('refund.created', 'PENDING', 1900));

    $this->assertSame([], $this->refunds());
    $this->assertSame('Completed', $this->processor->contributions[self::SIGNUP_CONTRIBUTION_ID]['status']);
  }

  /**
   * A refund CiviCRM recorded that Square then reports rejected.
   *
   * CiviCRM records only refunds Square has completed (see doRefund()), so
   * this should not happen; if it does, it is reported for manual follow-up.
   *
   * @dataProvider refusedRefundStatusProvider
   */
  public function testRefusedRefundAlreadyRecordedIsReportedNotReversed(string $status): void {
    $this->processor->payments[800] = [
      'contribution_id' => self::SIGNUP_CONTRIBUTION_ID,
      'trxn_id' => self::REFUND_ID,
      'total_amount' => -19.00,
      'payment_processor_id' => self::PROCESSOR_ID,
    ];

    $this->deliver($this->refundEvent('refund.updated', $status, 1900));

    $this->assertCount(1, $this->refunds());
    $expected = "Square refund REFUND-1 of payment " . self::FIRST['payment_id'] . " was {$status}, but CiviCRM recorded it as refunded on contribution " . self::SIGNUP_CONTRIBUTION_ID . '; reverse that refund manually.';
    $this->assertSame([['error', $expected]], Civi::$logged);
  }

  /**
   * @dataProvider refusedRefundStatusProvider
   */
  public function testRefusedRefundNeverRecordedIsIgnored(string $status): void {
    $this->deliver($this->refundEvent('refund.updated', $status, 1900));

    $this->assertSame([], $this->refunds());
    $this->assertSame([], Civi::$logged);
  }

  public static function refusedRefundStatusProvider(): array {
    return [['REJECTED'], ['FAILED']];
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

  public function testAdminRefundCompletedWhileAcquiringLockIsNotRecordedTwice(): void {
    $lock = $this->getMockBuilder(stdClass::class)->addMethods(['isAcquired', 'release'])->getMock();
    $lock->method('isAcquired')->willReturn(TRUE);
    $lock->expects($this->once())->method('release');
    Civi::$lockManager = $this->getMockBuilder(stdClass::class)->addMethods(['acquire'])->getMock();
    Civi::$lockManager->expects($this->once())->method('acquire')
      ->with('data.contribute.contribution.' . self::SIGNUP_CONTRIBUTION_ID)
      ->willReturnCallback(function () use ($lock) {
        // The admin action finishes recording while the webhook waits for
        // its contribution lock. The duplicate lookup must happen afterwards.
        $this->processor->payments[800] = [
          'contribution_id' => self::SIGNUP_CONTRIBUTION_ID,
          'trxn_id' => self::REFUND_ID,
          'total_amount' => -5.00,
          'payment_processor_id' => self::PROCESSOR_ID,
        ];
        return $lock;
      });

    $this->deliver($this->refundEvent('refund.updated', 'COMPLETED', 500));

    $this->assertCount(1, $this->refunds());
    $this->assertSame(-5.00, array_sum(array_column($this->refunds(), 'total_amount')));
  }

  public function testBusyContributionLockLeavesRefundForRetry(): void {
    $lock = $this->getMockBuilder(stdClass::class)->addMethods(['isAcquired', 'release'])->getMock();
    $lock->method('isAcquired')->willReturn(FALSE);
    $lock->expects($this->never())->method('release');
    Civi::$lockManager = $this->getMockBuilder(stdClass::class)->addMethods(['acquire'])->getMock();
    Civi::$lockManager->expects($this->once())->method('acquire')
      ->with('data.contribute.contribution.' . self::SIGNUP_CONTRIBUTION_ID)->willReturn($lock);

    try {
      $this->deliver($this->refundEvent('refund.updated', 'COMPLETED', 500));
      $this->fail('Lock contention must leave the webhook for retry.');
    }
    catch (CRM_Core_Payment_SquareRetryableException $e) {
      $this->assertStringContainsString('Could not acquire lock', $e->getMessage());
    }
    $this->assertSame([], $this->refunds());
  }

  public function testContributionLockCoversDuplicateCheckAndWrite(): void {
    $held = FALSE;
    $lock = $this->getMockBuilder(stdClass::class)->addMethods(['isAcquired', 'release'])->getMock();
    $lock->method('isAcquired')->willReturn(TRUE);
    $lock->expects($this->once())->method('release')->willReturnCallback(function () use (&$held) {
      $held = FALSE;
    });
    Civi::$lockManager = $this->getMockBuilder(stdClass::class)->addMethods(['acquire'])->getMock();
    Civi::$lockManager->expects($this->once())->method('acquire')
      ->with('data.contribute.contribution.' . self::SIGNUP_CONTRIBUTION_ID)
      ->willReturnCallback(function () use ($lock, &$held) {
        $held = TRUE;
        return $lock;
      });
    $reconciler = $this->getMockBuilder(CRM_Core_Payment_Square_FakeLedgerReconciler::class)
      ->setConstructorArgs([$this->callMethod($this->processor, 'gateway'), $this->processor])
      ->onlyMethods(['findContributionPayment', 'recordRefundPayment'])->getMock();
    $reconciler->expects($this->once())->method('findContributionPayment')
      ->with(self::SIGNUP_CONTRIBUTION_ID, self::REFUND_ID)
      ->willReturnCallback(function () use (&$held) {
        $this->assertTrue($held, 'The duplicate check must hold the contribution lock.');
        return NULL;
      });
    $reconciler->expects($this->once())->method('recordRefundPayment')
      ->willReturnCallback(function () use (&$held) {
        $this->assertTrue($held, 'The refund write must still hold the contribution lock.');
      });

    $event = $this->refundEvent('refund.updated', 'COMPLETED', 500);
    $reconciler->syncRefundFromSquare($event['data']['object']['refund']);

    $this->assertFalse($held);
  }

  /**
   * @dataProvider failingRefundOperationProvider
   */
  public function testContributionLockReleasedOnFailure(string $operation): void {
    $lock = $this->getMockBuilder(stdClass::class)->addMethods(['isAcquired', 'release'])->getMock();
    $lock->method('isAcquired')->willReturn(TRUE);
    $lock->expects($this->once())->method('release');
    Civi::$lockManager = $this->getMockBuilder(stdClass::class)->addMethods(['acquire'])->getMock();
    Civi::$lockManager->expects($this->once())->method('acquire')
      ->with('data.contribute.contribution.' . self::SIGNUP_CONTRIBUTION_ID)->willReturn($lock);
    $reconciler = $this->getMockBuilder(CRM_Core_Payment_Square_FakeLedgerReconciler::class)
      ->setConstructorArgs([$this->callMethod($this->processor, 'gateway'), $this->processor])
      ->onlyMethods([$operation])->getMock();
    $reconciler->expects($this->once())->method($operation)->willThrowException(new RuntimeException('Ledger failed.'));

    $event = $this->refundEvent('refund.updated', 'COMPLETED', 500);
    $this->expectException(RuntimeException::class);
    $this->expectExceptionMessage('Ledger failed.');
    $reconciler->syncRefundFromSquare($event['data']['object']['refund']);
  }

  public static function failingRefundOperationProvider(): array {
    return [['findContributionPayment'], ['recordRefundPayment']];
  }

  /**
   * A refund of a payment no contribution here records is ignored, once it is old.
   */
  public function testRefundOfAnUnknownPaymentIsIgnored(): void {
    $event = $this->refundEvent('refund.updated', 'COMPLETED', 1900, 'SOMEONE-ELSES-PAYMENT', gmdate('Y-m-d\TH:i:s\Z', time() - 7200));

    $this->deliver($event);

    $this->assertSame([], $this->refunds());
  }

  /**
   * A recent refund of a payment not recorded yet is retried, not dropped.
   */
  public function testRefundOfAPaymentNotRecordedYetIsRetried(): void {
    $event = $this->refundEvent('refund.updated', 'COMPLETED', 1900, self::SECOND['payment_id']);

    $this->expectException(CRM_Core_Payment_SquareRetryableException::class);
    $this->deliver($event);
  }

  /**
   * Square does not deliver webhooks in order: the refund may come first.
   */
  public function testRefundDeliveredBeforeItsPaymentIsRecordedOnRetry(): void {
    $refund = $this->refundEvent('refund.updated', 'COMPLETED', 1900, self::SECOND['payment_id']);
    try {
      $this->deliver($refund);
      $this->fail('The refund should have been left for a retry.');
    }
    catch (CRM_Core_Payment_SquareRetryableException $e) {
      // Left 'new' in the queue for the scheduled job.
    }
    $this->assertSame([], $this->refunds());

    $this->squareBills(self::SECOND);
    $this->deliver($this->invoicePaymentMadeEvent(self::SECOND));
    // The scheduled job retries the refund.
    $this->deliver($refund);

    $refunds = $this->refunds();
    $this->assertCount(1, $refunds);
    $this->assertSame(self::REFUND_ID, reset($refunds)['trxn_id']);
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
   * @param string $paymentId
   *   The refunded Square payment; the first installment's by default.
   * @param string|null $createdAt
   *   When Square created the refund; now by default.
   *
   * @return array
   */
  private function refundEvent(string $type, string $status, int $amountCents, string $paymentId = self::FIRST['payment_id'], ?string $createdAt = NULL): array {
    $now = $createdAt ?? gmdate('Y-m-d\TH:i:s\Z');
    return [
      'type' => $type,
      'event_id' => bin2hex(random_bytes(8)),
      'data' => [
        'type' => 'refund',
        'object' => [
          'refund' => [
            'id' => self::REFUND_ID,
            'payment_id' => $paymentId,
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
