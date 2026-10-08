<?php

use Civi\Api4\PaymentProcessor;

/**
 * Contacts and Square customers, against CiviCRM 6.16's real schema.
 *
 * @group headless
 */
class CRM_Square_ContactsHeadlessTest extends CRM_Square_HeadlessTestCase {

  public function testContactDetailsIncludeThePrimaryEmail(): void {
    $contactId = $this->createContact('Pat', 'pat@example.org');
    $customers = new class($this->gateway($this->squareClient())) extends CRM_Square_Customers {

      public function details(int $contactId): ?array {
        return $this->getContactDetails($contactId);
      }

    };

    // API4 Contact has no "email" field: it is email_primary.email.
    $this->assertSame('pat@example.org', $customers->details($contactId)['email']);
  }

  public function testAPaymentsEmailIdentifiesOnlyAnUnsharedAddress(): void {
    $patId = $this->createContact('Pat', 'pat@example.org');
    $this->createContact('Sam', 'family@example.org');
    $this->createContact('Alex', 'family@example.org');
    $reconciler = new class($this->gateway($this->squareClient())) extends CRM_Square_Reconciler {

      public function contactFor(array $payment): ?int {
        return $this->findContactIdForPayment($payment);
      }

    };

    $this->assertSame($patId, $reconciler->contactFor(['id' => 'P1', 'buyer_email_address' => 'pat@example.org']));
    $this->assertNull($reconciler->contactFor(['id' => 'P2', 'buyer_email_address' => 'family@example.org']));
  }

  public function testAPaymentsMappedCustomerIdentifiesItsContact(): void {
    $patId = $this->createContact('Pat');
    $this->mapCustomer($patId, 'CUSTOMER-PAT');
    $reconciler = new class($this->gateway($this->squareClient())) extends CRM_Square_Reconciler {

      public function contactFor(array $payment): ?int {
        return $this->findContactIdForPayment($payment);
      }

    };

    $this->assertSame($patId, $reconciler->contactFor(['id' => 'P1', 'customer_id' => 'CUSTOMER-PAT']));
  }

  public function testMergeMovesCustomerMappingsToTheKeptContact(): void {
    $keptId = $this->createContact('Pat', 'pat@example.org');
    $removedId = $this->createContact('Pat', 'pat@example.org');
    $this->mapCustomer($removedId, 'CUSTOMER-REMOVED');

    civicrm_api3('Contact', 'merge', ['to_keep_id' => $keptId, 'to_remove_id' => $removedId, 'mode' => 'aggressive']);

    $this->assertSame(['CUSTOMER-REMOVED' => $keptId], $this->customerMap());
  }

  public function testMergeKeepsTheKeptContactsOwnCustomer(): void {
    $keptId = $this->createContact('Pat', 'pat@example.org');
    $removedId = $this->createContact('Pat', 'pat@example.org');
    $this->mapCustomer($keptId, 'CUSTOMER-KEPT');
    $this->mapCustomer($removedId, 'CUSTOMER-REMOVED');

    // Without the merge hook's UPDATE IGNORE, the unique contact/processor
    // key would make the merge fail.
    civicrm_api3('Contact', 'merge', ['to_keep_id' => $keptId, 'to_remove_id' => $removedId, 'mode' => 'aggressive']);

    $this->assertSame(['CUSTOMER-KEPT' => $keptId], $this->customerMap());
  }

  public function testDeletingAProcessorDeletesItsMappings(): void {
    $this->mapCustomer($this->createContact('Pat'), 'CUSTOMER-PAT');

    PaymentProcessor::delete(FALSE)
      ->addWhere('id', '=', $this->processor['id'])
      ->execute();

    $this->assertSame([], $this->customerMap());
  }

}
