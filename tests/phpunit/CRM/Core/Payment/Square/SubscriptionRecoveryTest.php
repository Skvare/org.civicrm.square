<?php

require_once __DIR__ . '/SquareLedgerTestCase.php';

use Square\Types\Subscription;
use Square\Types\SubscriptionSource;

/**
 * Linking a subscription whose creation checkout could not confirm.
 *
 * Square created the subscription, but doRecurPayment() never got its ID
 * back (e.g. the response timed out), so the recurring contribution was not
 * linked to it. Square bills it anyway.
 */
class CRM_Core_Payment_Square_SubscriptionRecoveryTest extends CRM_Core_Payment_Square_SquareLedgerTestCase {

  /**
   * Checkout never learned the subscription ID.
   */
  protected function setUp(): void {
    parent::setUp();
    $this->processor->recurs[self::RECUR_ID]['processor_id'] = NULL;
  }

  public function testFirstInstallmentLinksTheSubscriptionAndCompletesTheCheckout(): void {
    $this->squareHasSubscription(['contact_id' => '7', 'recur_id' => (string) self::RECUR_ID]);

    $this->payFirstInstallment();

    $recur = $this->processor->recurs[self::RECUR_ID];
    $this->assertSame(self::SUBSCRIPTION_ID, $recur['processor_id']);
    $this->assertSame(self::SUBSCRIPTION_ID, $recur['trxn_id']);
    $this->assertCount(1, $this->seriesContributions());
    $this->assertSame('Completed', $this->processor->contributions[self::SIGNUP_CONTRIBUTION_ID]['status']);
    $this->assertSame(self::FIRST['payment_id'], $this->processor->contributions[self::SIGNUP_CONTRIBUTION_ID]['trxn_id']);
  }

  public function testSubscriptionWebhookLinksTheSubscription(): void {
    $this->squareHasSubscription(['contact_id' => '7', 'recur_id' => (string) self::RECUR_ID]);

    $this->deliver([
      'type' => 'subscription.created',
      'event_id' => 'EVENT-SUBSCRIPTION-CREATED',
      'data' => [
        'type' => 'subscription',
        'id' => self::SUBSCRIPTION_ID,
        'object' => ['subscription' => ['id' => self::SUBSCRIPTION_ID]],
      ],
    ]);

    $this->assertSame(self::SUBSCRIPTION_ID, $this->processor->recurs[self::RECUR_ID]['processor_id']);
    $this->assertSame(2, $this->processor->recurs[self::RECUR_ID]['contribution_status_id'], 'Still Pending until its first payment is recorded.');
  }

  /**
   * A subscription is linked only if everything about it checks out.
   *
   * @dataProvider unconfirmedSubscriptionProvider
   */
  public function testUnconfirmedSubscriptionIsNotLinked(callable $arrange): void {
    $this->squareHasSubscription(['contact_id' => '7', 'recur_id' => (string) self::RECUR_ID]);
    $arrange($this);

    try {
      $this->payFirstInstallment();
      $this->fail('A recent event for an unlinked subscription should be left for a retry.');
    }
    catch (CRM_Core_Payment_SquareRetryableException $e) {
      // As for any subscription not linked yet.
    }

    $this->assertNotSame(self::SUBSCRIPTION_ID, $this->processor->recurs[self::RECUR_ID]['processor_id'] ?? NULL);
    $this->assertSame([], $this->processor->payments);
  }

  public static function unconfirmedSubscriptionProvider(): array {
    return [
      'created outside CiviCRM, with no source' => [
        fn (self $test) => $test->squareHasSubscription(NULL),
      ],
      'source names another contact' => [
        fn (self $test) => $test->squareHasSubscription(['contact_id' => '8', 'recur_id' => (string) self::RECUR_ID]),
      ],
      'customer mapped to another contact' => [
        fn (self $test) => $test->processor->customerContacts[self::CUSTOMER_ID] = 8,
      ],
      'customer not mapped at all' => [
        function (self $test) {
          $test->processor->customerContacts = [];
        },
      ],
      'recurring contribution already linked to another subscription' => [
        fn (self $test) => $test->processor->recurs[self::RECUR_ID]['processor_id'] = 'ANOTHER-SUBSCRIPTION',
      ],
      'recurring contribution of another processor' => [
        fn (self $test) => $test->processor->recurs[self::RECUR_ID]['payment_processor_id'] = 43,
      ],
      'subscription at another location' => [
        fn (self $test) => $test->squareHasSubscription(['contact_id' => '7', 'recur_id' => (string) self::RECUR_ID], ['locationId' => 'ANOTHER-LOCATION']),
      ],
    ];
  }

  /**
   * Put the test subscription at (mocked) Square.
   *
   * @param array|null $source
   *   What doRecurPayment() puts in the subscription's source name; NULL
   *   for none.
   * @param array $values
   *   Other subscription values to override.
   */
  public function squareHasSubscription(?array $source, array $values = []): void {
    $values += [
      'id' => self::SUBSCRIPTION_ID,
      'status' => 'ACTIVE',
      'customerId' => self::CUSTOMER_ID,
      // The test processor's location (see processorConfig()).
      'locationId' => 'location-id',
    ];
    if ($source !== NULL) {
      $values['source'] = new SubscriptionSource(['name' => json_encode($source)]);
    }
    $this->squareSubscriptions[self::SUBSCRIPTION_ID] = new Subscription($values);
  }

}
