<?php

use PHPUnit\Framework\TestCase;

/**
 * Upgrade step 1003: Square catalog objects move from settings to a table.
 */
class CRM_Square_UpgraderTest extends TestCase {

  public function testCatalogObjectsMoveToTheirTable(): void {
    $upgrader = $this->upgrader([
      ['plan', '7_live_L1_Monthly donation', 'PLAN-A'],
      ['plan_variation', '7_live_L1_PLAN-A_MONTHLY_19_USD_indefinite', 'VAR-A'],
      ['plan_variation', '12_test_L2_PLAN-B_ANNUAL_50_USD_6', 'VAR-B'],
    ]);

    $this->assertTrue($upgrader->upgrade_1003());

    $this->assertTrue($upgrader->tableCreated);
    $this->assertSame([
      [7, 'plan', '7_live_L1_Monthly donation', 'PLAN-A'],
      [7, 'plan_variation', '7_live_L1_PLAN-A_MONTHLY_19_USD_indefinite', 'VAR-A'],
      [12, 'plan_variation', '12_test_L2_PLAN-B_ANNUAL_50_USD_6', 'VAR-B'],
    ], $upgrader->inserted, 'Each with the payment processor its key starts with.');
    $this->assertTrue($upgrader->settingsDeleted);
  }

  public function testKeyWithoutAProcessorIsSkipped(): void {
    $upgrader = $this->upgrader([['plan', 'live_L1_Monthly donation', 'PLAN-A']]);

    $upgrader->upgrade_1003();

    $this->assertSame([], $upgrader->inserted);
    $this->assertTrue($upgrader->settingsDeleted);
  }

  /**
   * An upgrader whose legacy settings hold the given catalog objects.
   *
   * @param array $legacyObjects
   *   [object type, key, Square catalog object ID] triples.
   *
   * @return \CRM_Square_Upgrader
   */
  private function upgrader(array $legacyObjects): CRM_Square_Upgrader {
    return new class($legacyObjects) extends CRM_Square_Upgrader {

      /**
       * @var bool
       */
      public bool $tableCreated = FALSE;

      /**
       * Rows inserted, as [processor ID, type, key, object ID].
       *
       * @var array
       */
      public array $inserted = [];

      /**
       * @var bool
       */
      public bool $settingsDeleted = FALSE;

      /**
       * @var array
       */
      private array $legacyObjects;

      /**
       * @param array $legacyObjects
       */
      public function __construct(array $legacyObjects) {
        $this->legacyObjects = $legacyObjects;
      }

      /**
       * Record instead of creating the table.
       */
      protected function createSquareCatalogMapTable(): void {
        $this->tableCreated = TRUE;
      }

      /**
       * @return array
       */
      protected function getLegacyCatalogObjects(): array {
        return $this->legacyObjects;
      }

      /**
       * Record instead of inserting.
       */
      protected function insertCatalogObject(int $processorId, string $type, string $key, string $objectId): void {
        $this->inserted[] = [$processorId, $type, $key, $objectId];
      }

      /**
       * Record instead of deleting.
       */
      protected function deleteLegacyCatalogSettings(): void {
        $this->settingsDeleted = TRUE;
      }

    };
  }

}
