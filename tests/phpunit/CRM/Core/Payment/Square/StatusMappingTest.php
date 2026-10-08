<?php

require_once __DIR__ . '/SquareUnitTestCase.php';

/**
 * Square status/instrument -> CiviCRM contribution_status_id mappings.
 *
 * These IDs matter: they're the same literals used directly throughout
 * CRM_Core_Payment_Square (1=Completed, 2=Pending, 3=Cancelled, 4=Failed,
 * 5=In Progress, 7=Refunded).
 */
class CRM_Core_Payment_Square_StatusMappingTest extends CRM_Core_Payment_Square_SquareUnitTestCase {

  /**
   * @dataProvider paymentStatusProvider
   */
  public function testMapPaymentStatus(string $squareStatus, ?int $expected): void {
    $actual = CRM_Square_Status::mapPaymentStatus($squareStatus);
    $this->assertSame($expected, $actual);
  }

  public static function paymentStatusProvider(): array {
    return [
      'completed' => ['COMPLETED', 1],
      // Authorized, but not captured: not a completed payment yet.
      'approved is still pending' => ['APPROVED', 2],
      'pending' => ['PENDING', 2],
      'processing' => ['PROCESSING', 2],
      'failed' => ['FAILED', 4],
      'declined' => ['DECLINED', 4],
      'canceled' => ['CANCELED', 4],
      'refunded' => ['REFUNDED', 7],
      'lowercase is normalized' => ['completed', 1],
      'unknown status is unmapped' => ['SOMETHING_NEW', NULL],
    ];
  }

  /**
   * @dataProvider subscriptionStatusProvider
   */
  public function testMapSquareSubscriptionStatusToCivi(string $squareStatus, ?int $expected): void {
    $actual = CRM_Square_Status::mapSubscriptionStatus($squareStatus);
    $this->assertSame($expected, $actual);
  }

  public static function subscriptionStatusProvider(): array {
    return [
      // Real Square subscription statuses: PENDING, ACTIVE, CANCELED,
      // DEACTIVATED, PAUSED, and (API versions 2025-09-24+) COMPLETED.
      // SUSPENDED does not exist and must never be matched/mapped.
      'pending maps to Pending' => ['PENDING', 2],
      'paused maps to Pending' => ['PAUSED', 2],
      'active maps to In Progress, not Completed' => ['ACTIVE', 5],
      'completed maps to Completed' => ['COMPLETED', 1],
      'canceled maps to Cancelled' => ['CANCELED', 3],
      'deactivated maps to Failed' => ['DEACTIVATED', 4],
      'nonexistent suspended status is unmapped' => ['SUSPENDED', NULL],
      'unknown status is unmapped' => ['SOMETHING_NEW', NULL],
    ];
  }

  /**
   * @dataProvider recurStatusChangeProvider
   */
  public function testRecurStatusChangeGuard(int $current, int $new, bool $expected): void {
    $reconciler = new CRM_Square_Reconciler(new CRM_Square_Gateway($this->processorConfig()));
    $actual = $this->callMethod($reconciler, 'isRecurStatusChangeAllowed', [$current, $new]);
    $this->assertSame($expected, $actual);
  }

  public static function recurStatusChangeProvider(): array {
    // 1=Completed, 2=Pending, 3=Cancelled, 4=Failed, 5=In Progress.
    return [
      // A late subscription.created (PENDING) must not undo the In Progress
      // that recording the first payment set.
      'never downgraded to Pending once In Progress' => [5, 2, FALSE],
      // ACTIVE says billing started, not that a payment was recorded.
      'not promoted to In Progress before its first payment' => [2, 5, FALSE],
      'restored to In Progress after a failure' => [4, 5, TRUE],
      // Square reports a cancelled subscription ACTIVE until its cancel date.
      'a cancelled series is not reopened' => [3, 5, FALSE],
      'a completed series is not reopened' => [1, 5, FALSE],
      'cancellation always applies' => [5, 3, TRUE],
      'cancellation applies before the first payment too' => [2, 3, TRUE],
      'completion applies' => [5, 1, TRUE],
      'deactivation applies' => [5, 4, TRUE],
    ];
  }

  /**
   * @dataProvider paymentInstrumentProvider
   */
  public function testMapPaymentInstrument(?string $sourceType, int $expected): void {
    $actual = CRM_Square_Status::mapPaymentInstrument($sourceType);
    $this->assertSame($expected, $actual);
  }

  public static function paymentInstrumentProvider(): array {
    return [
      'card' => ['CARD', 1],
      'wallet tokenizes as card' => ['WALLET', 1],
      'buy now pay later' => ['BUY_NOW_PAY_LATER', 1],
      'square account' => ['SQUARE_ACCOUNT', 1],
      'bank account is EFT' => ['BANK_ACCOUNT', 5],
      'cash' => ['CASH', 3],
      'unknown defaults to credit card' => ['SOMETHING_NEW', 1],
      'null defaults to credit card' => [NULL, 1],
    ];
  }

}
