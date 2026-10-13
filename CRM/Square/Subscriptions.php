<?php

use Square\SquareClient;
use Square\Types\Money;
use Square\Types\Subscription;
use Square\Types\SubscriptionPhase;
use Square\Types\SubscriptionPricing;
use Square\Types\CatalogObject;
use Square\Types\CatalogObjectBatch;
use Square\Types\CatalogObjectSubscriptionPlan;
use Square\Types\CatalogObjectSubscriptionPlanVariation;
use Square\Types\CatalogSubscriptionPlan;
use Square\Types\CatalogSubscriptionPlanVariation;
use Square\Catalog\Requests\BatchUpsertCatalogObjectsRequest;
use Square\Subscriptions\Requests\CancelSubscriptionsRequest;
use Square\Subscriptions\Requests\GetSubscriptionsRequest;
use Square\Subscriptions\Requests\UpdateSubscriptionRequest;

/**
 * Square subscription plans and subscriptions for one payment processor.
 *
 * Creates the Catalog subscription plans and plan variations a recurring
 * contribution's amount and cadence need (remembered in square_catalog_map,
 * so each is created once), and changes or cancels existing subscriptions.
 */
class CRM_Square_Subscriptions {

  /**
   * Square API access for the payment processor.
   *
   * @var \CRM_Square_Gateway
   */
  protected CRM_Square_Gateway $gateway;

  /**
   * Builds the plan and subscription service for one payment processor.
   *
   * @param \CRM_Square_Gateway $gateway
   */
  public function __construct(CRM_Square_Gateway $gateway) {
    $this->gateway = $gateway;
  }

  /**
   * Square-supported cadence definitions (Square's SubscriptionCadence).
   *
   * A CiviCRM frequency with no equivalent here (e.g. every 3 weeks) cannot
   * be billed by Square, and is refused at checkout.
   */
  protected const SQUARE_CADENCES = [
    'DAILY' => [
      'label' => 'Daily',
      'unit' => 'day',
      'step' => 1,
    ],
    'WEEKLY' => [
      'label' => 'Weekly',
      'unit' => 'week',
      'step' => 1,
    ],
    'EVERY_TWO_WEEKS' => [
      'label' => 'Every 2 Weeks',
      'unit' => 'week',
      'step' => 2,
    ],
    'THIRTY_DAYS' => [
      'label' => 'Every 30 Days',
      'unit' => 'day',
      'step' => 30,
    ],
    'SIXTY_DAYS' => [
      'label' => 'Every 60 Days',
      'unit' => 'day',
      'step' => 60,
    ],
    'NINETY_DAYS' => [
      'label' => 'Every 90 Days',
      'unit' => 'day',
      'step' => 90,
    ],
    'MONTHLY' => [
      'label' => 'Monthly',
      'unit' => 'month',
      'step' => 1,
    ],
    'EVERY_TWO_MONTHS' => [
      'label' => 'Every 2 Months',
      'unit' => 'month',
      'step' => 2,
    ],
    'QUARTERLY' => [
      'label' => 'Quarterly',
      'unit' => 'month',
      'step' => 3,
    ],
    'EVERY_FOUR_MONTHS' => [
      'label' => 'Every 4 Months',
      'unit' => 'month',
      'step' => 4,
    ],
    'EVERY_SIX_MONTHS' => [
      'label' => 'Every 6 Months',
      'unit' => 'month',
      'step' => 6,
    ],
    'ANNUAL' => [
      'label' => 'Annual',
      'unit' => 'year',
      'step' => 1,
    ],
    'EVERY_TWO_YEARS' => [
      'label' => 'Every 2 Years',
      'unit' => 'year',
      'step' => 2,
    ],
  ];

  /**
   * Get the plan variation ID for recurring-payment parameters.
   */
  public function getPlanVariationIdForParams(array $params): string {
    $entity = $params['component'] ?? 'contribute';
    $amount = (float) ($params['amount'] ?? 0);
    $currency = $params['currency'] ?? 'USD';
    $intervalUnit = $params['frequency_unit'] ?? '';
    $intervalStep = (int) ($params['frequency_interval'] ?? 1);
    $installments = (int) ($params['installments'] ?? 0);
    if (!$amount || !$intervalUnit) {
      throw new CRM_Core_Exception('Amount and cadence are required for Square subscription.');
    }

    // A plan identifies a subscription purpose; a variation identifies its amount and cadence.
    $planName = sprintf(
      'CiviCRM %s',
      ucfirst($entity)
    );

    $planId = $this->getOrCreateSubscriptionPlan($planName);
    CRM_Core_Payment_SquareDebugLogger::log("Square plan ID for {$entity}: {$planId}");
    return $this->getOrCreatePlanVariation(
      $planId, $amount,
      $currency, $intervalUnit,
      $intervalStep, $installments
    );
  }

  /**
   * Get or create a subscription plan variation with cadence.
   *
   * @param string $planId
   * @param float $amount
   * @param string $currency
   * @param string $intervalUnit
   *   Day, week, month, or year.
   * @param int $intervalStep
   * @param int|null $installments
   *
   * @return string Plan variation ID
   *
   * @throws CRM_Core_Exception
   */
  protected function getOrCreatePlanVariation(
    string $planId,
    float $amount,
    string $currency,
    string $intervalUnit,
    int $intervalStep = 1,
    ?int $installments = NULL,
  ): string {
    CRM_Core_Payment_SquareDebugLogger::log("Looking up Square plan variation: {$planId}, {$amount} {$currency}, every {$intervalStep} {$intervalUnit}");
    $cadence = $this->resolveCadence($intervalUnit, $intervalStep);
    CRM_Core_Payment_SquareDebugLogger::log("Resolved Square cadence: {$cadence}");
    // Scope the cache by everything that changes what gets created in
    // Square, not just plan/cadence/amount — two processor records (or a
    // staging clone) pointing at the same Square account/location must
    // never reuse each other's cached variation IDs, and a finite plan must
    // never reuse an open-ended one's.
    $cacheKey = implode('_', [
      $this->gateway->processorId(),
      $this->gateway->isTestMode() ? 'test' : 'live',
      $this->gateway->getLocationId(),
      $planId,
      $cadence,
      $amount,
      $currency,
      $installments ?: 'indefinite',
    ]);
    $savedId = $this->findCatalogObjectId('plan_variation', $cacheKey);
    if ($savedId !== NULL) {
      return $savedId;
    }

    $label = sprintf(
      '%s %0.2f %s',
      $cadence,
      $amount,
      $currency
    );
    CRM_Core_Payment_SquareDebugLogger::log("Creating label Square plan variation: {$label}");
    $amountMinorUnits = CRM_Square_Currency::toMinorUnits($amount, $currency);

    $phaseValues = [
      'ordinal' => 0,
    // MONTHLY, ANNUAL.
      'cadence' => strtoupper($cadence),
      'pricing' => new SubscriptionPricing([
        'type' => 'STATIC',
        'priceMoney' => new Money(['amount' => $amountMinorUnits, 'currency' => $currency]),
      ]),
    ];
    // Only finite subscriptions get a periods count — sending periods: 0
    // for an open-ended subscription is wrong (0 periods, not indefinite);
    // omitting the field entirely is what tells Square the phase never
    // ends. Square bills the first period itself (see doRecurPayment()), so
    // periods equals CiviCRM's installments.
    if (!empty($installments)) {
      $phaseValues['periods'] = $installments;
    }
    $phase = new SubscriptionPhase($phaseValues);

    $catalogObject = CatalogObject::subscriptionPlanVariation(new CatalogObjectSubscriptionPlanVariation([
      'id' => '#var_' . md5($cacheKey),
      'subscriptionPlanVariationData' => new CatalogSubscriptionPlanVariation([
        'name' => $label,
        'subscriptionPlanId' => $planId,
        'phases' => [$phase],
      ]),
    ]));

    $response = $this->gateway->call(fn (SquareClient $client) => $client->catalog->batchUpsert(new BatchUpsertCatalogObjectsRequest([
      'idempotencyKey' => $this->gateway->idempotencyKey('plan-variation', $cacheKey),
      'batches' => [new CatalogObjectBatch(['objects' => [$catalogObject]])],
    ])));

    $objects = $response->getObjects();
    $variationId = !empty($objects[0]) ? $objects[0]->getValue()->getId() : NULL;
    CRM_Core_Payment_SquareDebugLogger::log("Created Square plan variation ID: {$variationId}");
    if (!$variationId) {
      throw new CRM_Core_Exception('Failed to create Square plan variation.');
    }

    $this->saveCatalogObjectId('plan_variation', $cacheKey, $variationId);

    return $variationId;
  }

  /**
   * Resolve Square cadence from interval unit + frequency.
   *
   * @param string $unit
   *   Day, week, month, or year.
   * @param int $step
   *
   * @return string
   *
   * @throws CRM_Core_Exception
   */
  protected function resolveCadence(string $unit, int $step): string {

    foreach (self::SQUARE_CADENCES as $cadence => $def) {
      if ($def['unit'] === $unit && $def['step'] === $step) {
        return $cadence;
      }
    }

    throw new CRM_Core_Exception(
      "Unsupported Square cadence: every {$step} {$unit}(s)"
    );
  }

  /**
   * Get or create a Square Subscription Plan.
   *
   * @param string $name
   *
   * @return string Plan ID
   *
   * @throws CRM_Core_Exception
   */
  protected function getOrCreateSubscriptionPlan(string $name): string {
    // Scope the cache by processor + environment + location, same reasoning
    // as getOrCreatePlanVariation().
    $cacheKey = implode('_', [
      $this->gateway->processorId(),
      $this->gateway->isTestMode() ? 'test' : 'live',
      $this->gateway->getLocationId(),
      $name,
    ]);
    $savedId = $this->findCatalogObjectId('plan', $cacheKey);
    if ($savedId !== NULL) {
      return $savedId;
    }

    $catalogObject = CatalogObject::subscriptionPlan(new CatalogObjectSubscriptionPlan([
      'id' => '#plan_' . md5($cacheKey),
      'subscriptionPlanData' => new CatalogSubscriptionPlan([
        'name' => $name,
      ]),
    ]));

    $response = $this->gateway->call(fn (SquareClient $client) => $client->catalog->batchUpsert(new BatchUpsertCatalogObjectsRequest([
      'idempotencyKey' => $this->gateway->idempotencyKey('plan', $cacheKey),
      'batches' => [new CatalogObjectBatch(['objects' => [$catalogObject]])],
    ])));

    $objects = $response->getObjects();
    $planId = !empty($objects[0]) ? $objects[0]->getValue()->getId() : NULL;
    if (!$planId) {
      throw new CRM_Core_Exception('Failed to create Square subscription plan.');
    }

    $this->saveCatalogObjectId('plan', $cacheKey, $planId);

    return $planId;
  }

  /**
   * The Square catalog object created for a key, if any (see square_catalog_map).
   *
   * @param string $type
   *   Either 'plan' or 'plan_variation'.
   * @param string $key
   *   What it was created for (see getOrCreatePlanVariation() and
   *   getOrCreateSubscriptionPlan()).
   *
   * @return string|null
   *   Square catalog object ID.
   */
  protected function findCatalogObjectId(string $type, string $key): ?string {
    $objectId = CRM_Core_DAO::singleValueQuery(
      'SELECT square_object_id FROM square_catalog_map WHERE object_type = %1 AND object_key = %2',
      [1 => [$type, 'String'], 2 => [$key, 'String']]
    );
    return $objectId ? (string) $objectId : NULL;
  }

  /**
   * Remember the Square catalog object created for a key.
   *
   * Never forgotten: without it, the next payment for the same plan would
   * create a duplicate in the Square catalog.
   *
   * @param string $type
   *   Either 'plan' or 'plan_variation'.
   * @param string $key
   * @param string $objectId
   *   Square catalog object ID.
   */
  protected function saveCatalogObjectId(string $type, string $key, string $objectId): void {
    // A concurrent checkout may have saved it first: the idempotency key
    // makes Square return the same object to both.
    CRM_Core_DAO::executeQuery(
      'INSERT IGNORE INTO square_catalog_map (payment_processor_id, object_type, object_key, square_object_id) VALUES (%1, %2, %3, %4)',
      [
        1 => [$this->gateway->processorId(), 'Integer'],
        2 => [$type, 'String'],
        3 => [$key, 'String'],
        4 => [$objectId, 'String'],
      ]
    );
  }

  /**
   * Cancel a Square subscription.
   *
   * @param string $subscriptionId
   *   Square subscription ID.
   *
   * @throws \CRM_Core_Exception
   */
  public function cancel(string $subscriptionId): void {
    $this->gateway->call(fn (SquareClient $client) => $client->subscriptions->cancel(
      new CancelSubscriptionsRequest(['subscriptionId' => $subscriptionId])
    ));
  }

  /**
   * Change the amount every future installment of a subscription charges.
   *
   * Sets the subscription's price override, which replaces the STATIC price
   * of the plan variation it was created with (see getOrCreatePlanVariation()).
   *
   * @param string $subscriptionId
   *   Square subscription ID.
   * @param float $amount
   *   New installment amount, in major currency units.
   * @param string $currency
   *
   * @throws \CRM_Core_Exception
   */
  public function changeAmount(string $subscriptionId, float $amount, string $currency): void {
    $this->update($subscriptionId, [
      'priceOverrideMoney' => new Money([
        'amount' => CRM_Square_Currency::toMinorUnits($amount, $currency),
        'currency' => $currency,
      ]),
    ]);
  }

  /**
   * Charge a Square subscription's later invoices to another card.
   *
   * @param string $subscriptionId
   *   Square subscription ID.
   * @param string $cardId
   *   A card on file of the subscription's Square customer.
   *
   * @throws \CRM_Core_Exception
   */
  public function changeCard(string $subscriptionId, string $cardId): void {
    $this->update($subscriptionId, ['cardId' => $cardId]);
  }

  /**
   * The Square customer a subscription bills.
   *
   * @param string $subscriptionId
   *   Square subscription ID.
   *
   * @return string
   *   Square customer ID.
   *
   * @throws \CRM_Core_Exception
   */
  public function getCustomerId(string $subscriptionId): string {
    $customerId = (string) $this->getSubscription($subscriptionId)->getCustomerId();
    if ($customerId === '') {
      throw new CRM_Core_Exception("Square subscription {$subscriptionId} has no customer.");
    }
    return $customerId;
  }

  /**
   * A Square subscription, as Square reports it now.
   *
   * @param string $subscriptionId
   *
   * @return \Square\Types\Subscription
   *
   * @throws \CRM_Core_Exception
   */
  protected function getSubscription(string $subscriptionId): Subscription {
    $subscription = $this->gateway->call(fn (SquareClient $client) => $client->subscriptions->get(
      new GetSubscriptionsRequest(['subscriptionId' => $subscriptionId])
    ))->getSubscription();
    if (empty($subscription)) {
      throw new CRM_Core_Exception("Square subscription {$subscriptionId} not found.");
    }
    return $subscription;
  }

  /**
   * Change fields of a Square subscription.
   *
   * Square requires the subscription's current version on every update, to
   * reject concurrent changes, so it is read immediately before.
   *
   * @param string $subscriptionId
   * @param array $values
   *   Subscription fields to change.
   *
   * @throws \CRM_Core_Exception
   */
  protected function update(string $subscriptionId, array $values): void {
    $version = $this->getSubscription($subscriptionId)->getVersion();
    if ($version !== NULL) {
      $values['version'] = $version;
    }
    $this->gateway->call(fn (SquareClient $client) => $client->subscriptions->update(new UpdateSubscriptionRequest([
      'subscriptionId' => $subscriptionId,
      'subscription' => new Subscription($values),
    ])));
  }

}
