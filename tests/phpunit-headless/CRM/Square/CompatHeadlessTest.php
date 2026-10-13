<?php

use Civi\Api4\Contribution;
use Civi\Api4\ContributionRecur;
use Civi\Api4\LineItem;

/**
 * Calls into CiviCRM core that differ between versions, against a real CiviCRM.
 *
 * Run it on the oldest supported version (6.10) as well as the latest.
 *
 * @group headless
 */
class CRM_Square_CompatHeadlessTest extends CRM_Square_HeadlessTestCase {

  /**
   * The amount of a series of two items cannot be changed: it must count as two.
   *
   * See CRM_Core_Payment_Square::changeSubscriptionAmount().
   */
  public function testTemplateLineItemsAreListedOneByOne(): void {
    $contactId = $this->createContact('Pat');
    $recurId = (int) ContributionRecur::create(FALSE)
      ->setValues([
        'contact_id' => $contactId,
        'amount' => 19.00,
        'currency' => 'USD',
        'frequency_unit' => 'month',
        'frequency_interval' => 1,
        'financial_type_id:name' => 'Donation',
        'payment_processor_id' => $this->processor['id'],
        'contribution_status_id:name' => 'Pending',
        'is_email_receipt' => FALSE,
      ])
      ->execute()
      ->single()['id'];
    // With CiviCRM's default line item, for its whole amount.
    $contribution = Contribution::create(FALSE)
      ->setValues([
        'contact_id' => $contactId,
        'contribution_recur_id' => $recurId,
        'total_amount' => 19.00,
        'currency' => 'USD',
        'financial_type_id:name' => 'Donation',
        'contribution_status_id:name' => 'Pending',
      ])
      ->execute()
      ->single();

    $this->assertCount(1, CRM_Square_Compat::getTemplateLineItems($recurId));

    $firstLine = LineItem::get(FALSE)
      ->addWhere('contribution_id', '=', $contribution['id'])
      ->execute()
      ->single();
    LineItem::update(FALSE)
      ->addWhere('id', '=', $firstLine['id'])
      ->setValues(['unit_price' => 10.00, 'line_total' => 10.00])
      ->execute();
    LineItem::create(FALSE)
      ->setValues([
        'entity_table' => 'civicrm_contribution',
        'entity_id' => $contribution['id'],
        'contribution_id' => $contribution['id'],
        'price_field_id' => $firstLine['price_field_id'],
        'label' => 'Second item',
        'qty' => 1,
        'unit_price' => 9.00,
        'line_total' => 9.00,
        'financial_type_id' => $firstLine['financial_type_id'],
      ])
      ->execute();

    $lineItems = CRM_Square_Compat::getTemplateLineItems($recurId);
    $this->assertCount(2, $lineItems);
    $this->assertEqualsCanonicalizing([10.00, 9.00], array_map('floatval', array_column($lineItems, 'line_total')));
  }

}
