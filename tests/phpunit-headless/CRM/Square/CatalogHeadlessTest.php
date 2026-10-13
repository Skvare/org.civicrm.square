<?php

use Civi\Api4\PaymentProcessor;

/**
 * Square catalog objects remembered in square_catalog_map, against a real schema.
 *
 * @group headless
 */
class CRM_Square_CatalogHeadlessTest extends CRM_Square_HeadlessTestCase {

  public function testEachKeyKeepsTheFirstObjectSavedForIt(): void {
    $subscriptions = $this->subscriptions();

    $subscriptions->save('plan', 'KEY-1', 'PLAN-1');
    // E.g. a concurrent checkout's, which Square's idempotency makes the same.
    $subscriptions->save('plan', 'KEY-1', 'PLAN-2');

    $this->assertSame('PLAN-1', $subscriptions->find('plan', 'KEY-1'));
    $this->assertNull($subscriptions->find('plan_variation', 'KEY-1'), 'Plans and variations are kept apart.');
  }

  public function testDeletingAProcessorDeletesItsCatalogObjects(): void {
    $this->subscriptions()->save('plan', 'KEY-1', 'PLAN-1');

    PaymentProcessor::delete(FALSE)
      ->addWhere('id', '=', $this->processor['id'])
      ->execute();

    $this->assertNull($this->subscriptions()->find('plan', 'KEY-1'));
  }

  /**
   * Subscriptions for the test processor, with its catalog map exposed.
   *
   * @return \CRM_Square_Subscriptions
   */
  private function subscriptions(): CRM_Square_Subscriptions {
    return new class($this->gateway($this->squareClient())) extends CRM_Square_Subscriptions {

      /**
       * @return string|null
       */
      public function find(string $type, string $key): ?string {
        return $this->findCatalogObjectId($type, $key);
      }

      /**
       * Save a catalog object ID.
       */
      public function save(string $type, string $key, string $objectId): void {
        $this->saveCatalogObjectId($type, $key, $objectId);
      }

    };
  }

}
