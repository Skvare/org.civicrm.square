<?php

require_once __DIR__ . '/SquareUnitTestCase.php';

/**
 * IdempotencyKey() stability, scoping, and Square's 45-char hard limit.
 *
 * Square rejects idempotency keys over 45 characters with
 * "VALUE_TOO_LONG" -- this was a real production bug this extension hit,
 * so the 45-char guarantee is tested explicitly, not just assumed from the
 * substr() call.
 */
class CRM_Core_Payment_Square_IdempotencyTest extends CRM_Core_Payment_Square_SquareUnitTestCase {

  private function processor(array $overrides = []): CRM_Core_Payment_Square {
    $config = $this->processorConfig($overrides);
    return new CRM_Core_Payment_Square('live', $config);
  }

  private function key(CRM_Core_Payment_Square $processor, string $operation, string $reference): string {
    return $this->callMethod($processor, 'idempotencyKey', [$operation, $reference]);
  }

  public function testKeyIsStableForTheSameOperationAndReference(): void {
    $processor = $this->processor();
    $this->assertSame(
      $this->key($processor, 'payment', 'invoice-123'),
      $this->key($processor, 'payment', 'invoice-123')
    );
  }

  public function testKeyDiffersByOperation(): void {
    $processor = $this->processor();
    $this->assertNotSame(
      $this->key($processor, 'payment', 'invoice-123'),
      $this->key($processor, 'refund', 'invoice-123')
    );
  }

  public function testKeyDiffersBetweenSubscriptionAndSubscriptionInit(): void {
    // Regression test for the generic idempotencyKey() helper: two
    // operations sharing the same reference (e.g. a recur ID) must never
    // collide. doRecurPayment() no longer makes a separate
    // 'subscription-init' call itself (removed to fix the double-charge
    // bug -- the initial charge is now handled entirely by Square's own
    // subscription billing), but the helper's collision-avoidance
    // guarantee is still load-bearing for every other caller.
    $processor = $this->processor();
    $recurId = '999';
    $subscriptionKey = $this->key($processor, 'subscription', $recurId);
    $initKey = $this->key($processor, 'subscription-init', $recurId);

    $this->assertNotSame($subscriptionKey, $initKey);
    $this->assertLessThanOrEqual(45, strlen($subscriptionKey));
    $this->assertLessThanOrEqual(45, strlen($initKey));
  }

  public function testKeyDiffersBetweenLiveAndSandboxForTheSameReference(): void {
    $live = $this->processor(['is_test' => FALSE]);
    $sandbox = $this->processor(['is_test' => TRUE]);

    $this->assertNotSame(
      $this->key($live, 'payment', 'invoice-123'),
      $this->key($sandbox, 'payment', 'invoice-123')
    );
  }

  public function testKeyDiffersBetweenPaymentProcessorInstances(): void {
    $processorA = $this->processor(['id' => 1]);
    $processorB = $this->processor(['id' => 2]);

    $this->assertNotSame(
      $this->key($processorA, 'payment', 'invoice-123'),
      $this->key($processorB, 'payment', 'invoice-123')
    );
  }

  /**
   * @dataProvider referenceProvider
   */
  public function testKeyNeverExceeds45Characters(string $operation, string $reference): void {
    $processor = $this->processor();
    $this->assertLessThanOrEqual(45, strlen($this->key($processor, $operation, $reference)));
  }

  public static function referenceProvider(): array {
    return [
      'short reference' => ['payment', '1'],
      'long invoice reference' => ['payment', str_repeat('invoice-id-', 10)],
      'subscription-init with a large recur id' => ['subscription-init', '999999999'],
      'refund composite reference' => ['refund', 'pay_abcdef1234567890:150000'],
    ];
  }

}
