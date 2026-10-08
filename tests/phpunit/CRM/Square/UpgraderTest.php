<?php

use PHPUnit\Framework\TestCase;

/**
 * Upgrade steps 1001 and 1002.
 *
 * One contact per Square customer, per payment processor; and deleting a
 * processor deletes its mappings.
 */
class CRM_Square_UpgraderTest extends TestCase {

  public function testConflictingMappingsStopTheUpgradeWithoutChangingTheTable(): void {
    $upgrader = $this->upgrader(FALSE, [
      ['payment_processor_id' => 7, 'square_customer_id' => 'CUST-A', 'contact_ids' => '12,34'],
      ['payment_processor_id' => 9, 'square_customer_id' => 'CUST-B', 'contact_ids' => '56,78,90'],
    ]);

    try {
      $upgrader->upgrade_1001();
      $this->fail('Expected the upgrade to stop.');
    }
    catch (CRM_Core_Exception $e) {
      // Every conflict, and what to do about it.
      $this->assertStringContainsString('2 Square customer(s)', $e->getMessage());
      $this->assertStringContainsString('payment processor 7, Square customer CUST-A: contacts 12, 34', $e->getMessage());
      $this->assertStringContainsString('payment processor 9, Square customer CUST-B: contacts 56, 78, 90', $e->getMessage());
      $this->assertStringContainsString('delete the other contacts', $e->getMessage());
    }
    $this->assertFalse($upgrader->keyAdded, 'No mapping is re-assigned or dropped automatically.');
  }

  public function testKeyIsAddedWhenNoMappingsConflict(): void {
    $upgrader = $this->upgrader(FALSE, []);

    $this->assertTrue($upgrader->upgrade_1001());

    $this->assertTrue($upgrader->keyAdded);
  }

  public function testUpgradeIsANoOpWhenTheKeyExists(): void {
    // E.g. installed after the key was added to the table definition.
    $upgrader = $this->upgrader(TRUE, [
      ['payment_processor_id' => 7, 'square_customer_id' => 'CUST-A', 'contact_ids' => '12,34'],
    ]);

    $this->assertTrue($upgrader->upgrade_1001());

    $this->assertFalse($upgrader->conflictsChecked);
    $this->assertFalse($upgrader->keyAdded);
  }

  public function testProcessorDeletesCascadeToMappings(): void {
    $upgrader = $this->upgrader(TRUE, []);

    $this->assertTrue($upgrader->upgrade_1002());

    $this->assertTrue($upgrader->cascadeAdded);
  }

  /**
   * An upgrader whose square_customer_map is described by its arguments.
   *
   * @param bool $hasKey
   * @param array $conflicts
   *
   * @return \CRM_Square_Upgrader
   */
  private function upgrader(bool $hasKey, array $conflicts): CRM_Square_Upgrader {
    return new class($hasKey, $conflicts) extends CRM_Square_Upgrader {

      /**
       * @var bool
       */
      public bool $keyAdded = FALSE;

      /**
       * @var bool
       */
      public bool $conflictsChecked = FALSE;

      /**
       * @var bool
       */
      public bool $cascadeAdded = FALSE;

      /**
       * @var bool
       */
      private bool $hasKey;

      /**
       * @var array
       */
      private array $conflicts;

      /**
       * @param bool $hasKey
       * @param array $conflicts
       */
      public function __construct(bool $hasKey, array $conflicts) {
        $this->hasKey = $hasKey;
        $this->conflicts = $conflicts;
      }

      /**
       * @return bool
       */
      protected function hasUniqueCustomerKey(): bool {
        return $this->hasKey;
      }

      /**
       * @return array
       */
      protected function findConflictingCustomerMappings(): array {
        $this->conflictsChecked = TRUE;
        return $this->conflicts;
      }

      /**
       * Record instead of altering the table.
       */
      protected function addUniqueCustomerKey(): void {
        $this->keyAdded = TRUE;
      }

      /**
       * Record instead of altering the table.
       */
      protected function cascadeProcessorDeletes(): void {
        $this->cascadeAdded = TRUE;
      }

    };
  }

}
