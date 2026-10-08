<?php

require_once __DIR__ . '/SquareLedgerTestCase.php';

use Square\Types\Subscription;

/**
 * Applying Square subscription changes (subscription.created / .updated).
 *
 * Square has no subscription.canceled event: a cancellation arrives as a
 * subscription.updated with a canceled_date, while its status is still
 * ACTIVE.
 */
class CRM_Core_Payment_Square_SubscriptionSyncTest extends CRM_Core_Payment_Square_SquareLedgerTestCase {

  /**
   * @dataProvider statusesUntilCanceledDateProvider
   */
  public function testCancellationInSquareArrivesBeforeTheStatusChanges(string $status): void {
    $this->payFirstInstallment();
    // As Square sends it when the subscription is cancelled in its
    // dashboard: ACTIVE (or PENDING or PAUSED) until the end of the period
    // already paid for.
    $this->squareSubscriptions[self::SUBSCRIPTION_ID] = new Subscription([
      'id' => self::SUBSCRIPTION_ID,
      'status' => $status,
      'canceledDate' => '2026-10-07',
      'chargedThroughDate' => '2026-10-07',
    ]);

    $this->deliver($this->subscriptionUpdatedEvent());

    $recur = $this->processor->recurs[self::RECUR_ID];
    $this->assertSame(3, $recur['contribution_status_id'], 'The series is Cancelled.');
    $this->assertSame('2026-10-07 00:00:00', $recur['cancel_date']);
  }

  public static function statusesUntilCanceledDateProvider(): array {
    return [
      'ACTIVE' => ['ACTIVE'],
      'PENDING' => ['PENDING'],
      'PAUSED' => ['PAUSED'],
    ];
  }

  public function testDeactivatedSubscriptionWithCanceledDateIsFailedNotCancelled(): void {
    $this->payFirstInstallment();
    $this->squareSubscriptions[self::SUBSCRIPTION_ID] = new Subscription([
      'id' => self::SUBSCRIPTION_ID,
      'status' => 'DEACTIVATED',
      'canceledDate' => '2026-10-07',
    ]);

    $this->deliver($this->subscriptionUpdatedEvent());

    $recur = $this->processor->recurs[self::RECUR_ID];
    $this->assertSame(4, $recur['contribution_status_id'], 'Square stopped billing a failing card: the series is Failed.');
    $this->assertEmpty($recur['cancel_date'] ?? NULL);
  }

  public function testActiveSubscriptionWithoutCanceledDateIsNotCancelled(): void {
    $this->payFirstInstallment();
    $statusBefore = $this->processor->recurs[self::RECUR_ID]['contribution_status_id'];
    $this->squareSubscriptions[self::SUBSCRIPTION_ID] = new Subscription([
      'id' => self::SUBSCRIPTION_ID,
      'status' => 'ACTIVE',
    ]);

    $this->deliver($this->subscriptionUpdatedEvent());

    $recur = $this->processor->recurs[self::RECUR_ID];
    $this->assertSame($statusBefore, $recur['contribution_status_id']);
    $this->assertNotSame(3, $recur['contribution_status_id']);
    $this->assertEmpty($recur['cancel_date'] ?? NULL);
  }

  public function testCancellationArrivesAsSubscriptionUpdated(): void {
    $this->payFirstInstallment();
    $this->squareSubscriptions[self::SUBSCRIPTION_ID] = new Subscription([
      'id' => self::SUBSCRIPTION_ID,
      'status' => 'CANCELED',
      'canceledDate' => '2026-10-15',
    ]);

    $this->deliver($this->subscriptionUpdatedEvent());

    $recur = $this->processor->recurs[self::RECUR_ID];
    $this->assertSame(3, $recur['contribution_status_id'], 'The series is Cancelled.');
    $this->assertSame('2026-10-15 00:00:00', $recur['cancel_date']);
  }

  public function testActiveSubscriptionDoesNotReopenASeriesCancelledInCiviCrm(): void {
    $this->payFirstInstallment();
    // Cancelled in CiviCRM; Square keeps it ACTIVE until its canceled_date.
    $this->processor->recurs[self::RECUR_ID]['contribution_status_id'] = 3;
    $this->squareSubscriptions[self::SUBSCRIPTION_ID] = new Subscription([
      'id' => self::SUBSCRIPTION_ID,
      'status' => 'ACTIVE',
      'canceledDate' => '2026-10-15',
    ]);

    $this->deliver($this->subscriptionUpdatedEvent());

    $this->assertSame(3, $this->processor->recurs[self::RECUR_ID]['contribution_status_id']);
  }

  public function testRemovedEventTypesAreNotSubscribed(): void {
    $supported = CRM_Core_Payment_SquareIPN::getSupportedEventTypes();

    // Neither exists in Square's event catalog.
    $this->assertNotContains('subscription.canceled', $supported);
    $this->assertNotContains('invoice.payment_failed', $supported);
    $this->assertContains('invoice.scheduled_charge_failed', $supported);
  }

  /**
   * A subscription.updated payload for the test series.
   *
   * @return array
   */
  private function subscriptionUpdatedEvent(): array {
    return [
      'type' => 'subscription.updated',
      'event_id' => 'EVENT-SUBSCRIPTION-UPDATED',
      'data' => [
        'type' => 'subscription',
        'id' => self::SUBSCRIPTION_ID,
        'object' => ['subscription' => ['id' => self::SUBSCRIPTION_ID]],
      ],
    ];
  }

}
