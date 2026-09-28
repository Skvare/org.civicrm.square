<?php

require_once __DIR__ . '/SquareUnitTestCase.php';

use Square\Catalog\CatalogClient;
use Square\Catalog\Requests\BatchUpsertCatalogObjectsRequest;
use Square\SquareClient;
use Square\Subscriptions\Requests\UpdateSubscriptionRequest;
use Square\Subscriptions\SubscriptionsClient;
use Square\Types\BatchUpsertCatalogObjectsResponse;
use Square\Types\CatalogObject;
use Square\Types\CatalogObjectSubscriptionPlan;
use Square\Types\CatalogObjectSubscriptionPlanVariation;
use Square\Types\GetSubscriptionResponse;
use Square\Types\Subscription;
use Square\Types\SubscriptionPhase;
use Square\Types\UpdateSubscriptionResponse;

/**
 * Square subscription plans and changes to existing subscriptions.
 */
class CRM_Core_Payment_Square_SubscriptionsTest extends CRM_Core_Payment_Square_SquareUnitTestCase {

  /**
   * Plan variations sent to Square's catalog, in order.
   *
   * @var \Square\Types\CatalogObjectSubscriptionPlanVariation[]
   */
  private array $variations = [];

  /**
   * Idempotency keys of every catalog upsert, in order.
   *
   * @var string[]
   */
  private array $upsertKeys = [];

  protected function setUp(): void {
    parent::setUp();
    Civi::$settings = [];
  }

  /**
   * A finite recurring contribution bills exactly its installments.
   */
  public function testFiniteSubscriptionHasOnePeriodPerInstallment(): void {
    $this->subscriptions()->getPlanVariationIdForParams($this->params(['installments' => 12]));

    $phase = $this->onlyPhase();
    $this->assertSame(12, $phase->getPeriods());
    $this->assertSame('MONTHLY', $phase->getCadence());
    $this->assertSame('STATIC', $phase->getPricing()->getType());
    $this->assertSame(1900, $phase->getPricing()->getPriceMoney()->getAmount());
    $this->assertSame('USD', $phase->getPricing()->getPriceMoney()->getCurrency());
  }

  /**
   * An open-ended one sends no periods at all (0 would mean no periods).
   *
   * @dataProvider openEndedProvider
   */
  public function testOpenEndedSubscriptionOmitsPeriods(array $installments): void {
    $this->subscriptions()->getPlanVariationIdForParams($this->params($installments));

    $this->assertNull($this->onlyPhase()->getPeriods());
  }

  public static function openEndedProvider(): array {
    return [
      'zero installments' => [['installments' => 0]],
      'no installments' => [[]],
    ];
  }

  /**
   * Plans are cached, but a finite plan never reuses an open-ended one's.
   */
  public function testCachedVariationsAreKeptApartByInstallments(): void {
    $subscriptions = $this->subscriptions();

    $open = $subscriptions->getPlanVariationIdForParams($this->params());
    $this->assertSame($open, $subscriptions->getPlanVariationIdForParams($this->params()), 'Reused from the cache.');
    $finite = $subscriptions->getPlanVariationIdForParams($this->params(['installments' => 6]));

    $this->assertNotSame($open, $finite);
    $this->assertCount(2, $this->variations, 'One variation for each; the repeat came from the cache.');
  }

  /**
   * Retrying a catalog write after a lost response cannot create a duplicate.
   */
  public function testCatalogIdempotencyKeysAreStableAndDistinct(): void {
    $this->subscriptions()->getPlanVariationIdForParams($this->params());
    $firstKeys = $this->upsertKeys;

    // Nothing cached, as after a failure between Square's reply and caching.
    Civi::$settings = [];
    $this->upsertKeys = [];
    $this->subscriptions()->getPlanVariationIdForParams($this->params());

    $this->assertSame($firstKeys, $this->upsertKeys);
    $this->assertCount(2, array_unique($firstKeys), 'The plan and its variation use different keys.');
    foreach ($firstKeys as $key) {
      $this->assertLessThanOrEqual(45, strlen($key));
    }
  }

  /**
   * An unsupported cadence is refused rather than approximated.
   */
  public function testUnsupportedCadenceIsRefused(): void {
    $this->expectException(CRM_Core_Exception::class);
    $this->expectExceptionMessage('Unsupported Square cadence');
    $this->subscriptions()->getPlanVariationIdForParams($this->params(['frequency_interval' => 5]));
  }

  /**
   * Changing the amount sends Square the subscription's current version.
   */
  public function testChangeAmountOverridesThePriceAtTheCurrentVersion(): void {
    /** @var \Square\Subscriptions\Requests\UpdateSubscriptionRequest|null $captured */
    $captured = NULL;
    $subscriptionsClient = $this->createMock(SubscriptionsClient::class);
    $subscriptionsClient->method('get')->willReturn(new GetSubscriptionResponse([
      'subscription' => new Subscription(['id' => 'SUB-1', 'version' => 7]),
    ]));
    $subscriptionsClient->method('update')->willReturnCallback(function (UpdateSubscriptionRequest $request) use (&$captured) {
      $captured = $request;
      return new UpdateSubscriptionResponse(['subscription' => $request->getSubscription()]);
    });

    $this->subscriptions(['subscriptions' => $subscriptionsClient])->changeAmount('SUB-1', 25.00, 'USD');

    $this->assertNotNull($captured);
    $this->assertSame('SUB-1', $captured->getSubscriptionId());
    $this->assertSame(7, $captured->getSubscription()->getVersion());
    $this->assertSame(2500, $captured->getSubscription()->getPriceOverrideMoney()->getAmount());
    $this->assertSame('USD', $captured->getSubscription()->getPriceOverrideMoney()->getCurrency());
  }

  /**
   * @param array $overrides
   *
   * @return array
   *   doRecurPayment() params for a monthly 19.00 USD contribution.
   */
  private function params(array $overrides = []): array {
    return $overrides + [
      'amount' => '19.00',
      'currency' => 'USD',
      'frequency_unit' => 'month',
      'frequency_interval' => 1,
    ];
  }

  /**
   * @param array $mockSubClients
   *   SquareClient sub-clients to use instead of the recording catalog.
   *
   * @return \CRM_Square_Subscriptions
   */
  private function subscriptions(array $mockSubClients = []): CRM_Square_Subscriptions {
    $catalog = $this->createMock(CatalogClient::class);
    $catalog->method('batchUpsert')->willReturnCallback(function (BatchUpsertCatalogObjectsRequest $request) {
      $this->upsertKeys[] = $request->getIdempotencyKey();
      $object = $request->getBatches()[0]->getObjects()[0]->getValue();
      if ($object instanceof CatalogObjectSubscriptionPlanVariation) {
        $this->variations[] = $object;
        $saved = CatalogObject::subscriptionPlanVariation(new CatalogObjectSubscriptionPlanVariation(['id' => 'VARIATION-' . count($this->variations)]));
      }
      else {
        $this->assertInstanceOf(CatalogObjectSubscriptionPlan::class, $object);
        $saved = CatalogObject::subscriptionPlan(new CatalogObjectSubscriptionPlan(['id' => 'PLAN-1']));
      }
      return new BatchUpsertCatalogObjectsResponse(['objects' => [$saved]]);
    });

    $client = new SquareClient(token: 'test-token', options: ['baseUrl' => 'https://example.invalid']);
    $client->catalog = $catalog;
    foreach ($mockSubClients as $property => $mock) {
      $client->$property = $mock;
    }
    return new CRM_Square_Subscriptions(new CRM_Square_Gateway($this->processorConfig(), fn () => $client));
  }

  /**
   * The single phase of the one plan variation sent to Square.
   *
   * @return \Square\Types\SubscriptionPhase
   */
  private function onlyPhase(): SubscriptionPhase {
    $this->assertCount(1, $this->variations);
    $phases = $this->variations[0]->getSubscriptionPlanVariationData()->getPhases();
    $this->assertCount(1, $phases);
    return $phases[0];
  }

}
