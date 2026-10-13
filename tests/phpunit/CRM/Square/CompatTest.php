<?php

use PHPUnit\Framework\TestCase;

/**
 * Calls into CiviCRM core that differ between versions (CRM_Square_Compat).
 */
class CRM_Square_CompatTest extends TestCase {

  /**
   * Before CiviCRM 6.15, getTemplateContribution() keys line items by price set.
   */
  public function testLineItemsKeyedByPriceSetAreListedOneByOne(): void {
    $lineItemsByPriceSet = [
      3 => [
        11 => ['id' => 11, 'line_total' => 10.00],
        12 => ['id' => 12, 'line_total' => 9.00],
      ],
    ];

    $lineItems = CRM_Square_Compat::flattenLineItems($lineItemsByPriceSet);

    $this->assertCount(2, $lineItems, 'Two line items, not one price set.');
    $this->assertSame([11, 12], array_column($lineItems, 'id'));
  }

  public function testNoLineItems(): void {
    $this->assertSame([], CRM_Square_Compat::flattenLineItems([]));
  }

}
