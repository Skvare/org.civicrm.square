<?php

use PHPUnit\Framework\TestCase;

/**
 * Square customer mappings follow a contact merge (square_civicrm_merge()).
 */
class CRM_Square_MergeHookTest extends TestCase {

  public function testMappingsMoveToTheKeptContact(): void {
    $sqls = ['SELECT 1'];

    square_civicrm_merge('sqls', $sqls, 5, 9, []);

    $this->assertSame([
      'SELECT 1',
      // Where the kept contact already has a customer on the processor, the
      // unique contact/processor key makes UPDATE IGNORE skip the row...
      'UPDATE IGNORE square_customer_map SET contact_id = 5 WHERE contact_id = 9',
      // ...which is then dropped.
      'DELETE FROM square_customer_map WHERE contact_id = 9',
    ], $sqls);
  }

  public function testOtherMergeOperationsAreLeftAlone(): void {
    $cidRefs = ['civicrm_email' => ['contact_id']];

    square_civicrm_merge('cidRefs', $cidRefs);

    // A plain UPDATE from cidRefs would fail on the unique key.
    $this->assertSame(['civicrm_email' => ['contact_id']], $cidRefs);
  }

}
