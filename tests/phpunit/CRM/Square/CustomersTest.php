<?php

require_once dirname(__DIR__) . '/Core/Payment/Square/SquareUnitTestCase.php';

use Square\Customers\CustomersClient;
use Square\Customers\Requests\CreateCustomerRequest;
use Square\Customers\Requests\SearchCustomersRequest;
use Square\Exceptions\SquareException;
use Square\SquareClient;
use Square\Types\CreateCustomerResponse;
use Square\Types\Customer;
use Square\Types\SearchCustomersResponse;
use Square\Types\UpdateCustomerResponse;

/**
 * Which Square customer a contact's recurring payments use.
 */
class CRM_Square_CustomersTest extends CRM_Core_Payment_Square_SquareUnitTestCase {

  /**
   * Square customers, as [id, reference_id, email].
   *
   * @var array
   */
  private array $squareCustomers = [];

  /**
   * Customers created at (mocked) Square.
   *
   * @var \Square\Customers\Requests\CreateCustomerRequest[]
   */
  private array $created = [];

  /**
   * Searches made at (mocked) Square.
   *
   * @var \Square\Customers\Requests\SearchCustomersRequest[]
   */
  private array $searches = [];

  /**
   * Whether (mocked) Square refuses customer updates.
   *
   * @var bool
   */
  private bool $updateFails = FALSE;

  public function testMappedCustomerIsUsed(): void {
    $customers = $this->customers([12 => 'CUST-MAPPED']);

    $this->assertSame('CUST-MAPPED', $customers->ensureSquareCustomer(['contactID' => 12]));
    $this->assertSame([], $this->created);
  }

  /**
   * One this extension could have created for the contact.
   *
   * @dataProvider sameEmailProvider
   */
  public function testCustomerWithTheContactsReferenceAndEmailIsAdopted(string $email): void {
    $this->squareCustomers[] = ['CUST-REF', '12', $email];
    $customers = $this->customers([]);

    $this->assertSame('CUST-REF', $customers->ensureSquareCustomer(['contactID' => 12]));
    $this->assertSame([12 => 'CUST-REF'], $customers->map);
    $this->assertSame([], $this->created);
  }

  public static function sameEmailProvider(): array {
    return [
      'same email' => ['pat@example.org'],
      'same email, other case and spacing' => [' Pat@Example.ORG'],
    ];
  }

  /**
   * Another integration's customer may carry the same reference_id.
   */
  public function testCustomerWithTheContactsReferenceButAnotherEmailIsNotAdopted(): void {
    $this->squareCustomers[] = ['CUST-OTHER-APP', '12', 'someone.else@example.org'];
    $customers = $this->customers([]);

    $this->assertSame('CUST-NEW-1', $customers->ensureSquareCustomer(['contactID' => 12]));
    $this->assertSame([12 => 'CUST-NEW-1'], $customers->map);
  }

  /**
   * E.g. a parent's Square Online customer, for a child who shares the email.
   */
  public function testCustomerWithOnlyTheContactsEmailIsNotAdopted(): void {
    $this->squareCustomers[] = ['CUST-POS', NULL, 'pat@example.org'];
    $customers = $this->customers([]);

    $this->assertSame('CUST-NEW-1', $customers->ensureSquareCustomer(['contactID' => 12]));
    $this->assertCount(1, $this->searches);
    $this->assertNull($this->searches[0]->getQuery()->getFilter()->getEmailAddress(), 'Customers are never looked up by email.');
  }

  /**
   * A parent's email on two children's records must not block the second.
   */
  public function testSharedEmailGetsANewCustomerInsteadOfFailing(): void {
    $this->squareCustomers[] = ['CUST-SIBLING', '34', 'pat@example.org'];
    $customers = $this->customers([34 => 'CUST-SIBLING']);

    $customerId = $customers->ensureSquareCustomer(['contactID' => 12]);

    $this->assertSame('CUST-NEW-1', $customerId);
    $this->assertCount(1, $this->created);
    $this->assertSame('12', $this->created[0]->getReferenceId());
    $this->assertSame('pat@example.org', $this->created[0]->getEmailAddress());
    $this->assertSame([34 => 'CUST-SIBLING', 12 => 'CUST-NEW-1'], $customers->map);
  }

  /**
   * The payment does not depend on the customer's name and email at Square.
   */
  public function testCustomerDetailsUpdateFailingDoesNotFailTheCheckout(): void {
    $this->updateFails = TRUE;
    Civi::$logged = [];

    $params = ['contactID' => 12, 'email' => 'pat@example.org'];

    $this->customers([12 => 'CUST-MAPPED'])->updateSquareCustomerDetails('CUST-MAPPED', $params);

    $this->assertSame('warning', Civi::$logged[0][0] ?? NULL);
    $this->assertStringContainsString('could not update the details of Square customer CUST-MAPPED', Civi::$logged[0][1]);
  }

  /**
   * Customers whose mapping lives in an array, and whose Square is mocked.
   *
   * @param array $map
   *   Square customer ID by contact ID.
   *
   * @return \CRM_Square_Customers
   */
  private function customers(array $map): CRM_Square_Customers {
    $client = new SquareClient(token: 'test-token', options: ['baseUrl' => 'https://example.invalid']);
    $mock = $this->createMock(CustomersClient::class);
    $mock->method('search')->willReturnCallback(function (SearchCustomersRequest $request) {
      $this->searches[] = $request;
      $filter = $request->getQuery()->getFilter();
      $matches = [];
      foreach ($this->squareCustomers as [$id, $referenceId, $email]) {
        if (($filter->getReferenceId() && $filter->getReferenceId()->getExact() === $referenceId)
          || ($filter->getEmailAddress() && $filter->getEmailAddress()->getExact() === $email)) {
          $matches[] = new Customer(['id' => $id, 'referenceId' => $referenceId, 'emailAddress' => $email]);
        }
      }
      return new SearchCustomersResponse(['customers' => $matches]);
    });
    $mock->method('update')->willReturnCallback(function () {
      if ($this->updateFails) {
        throw new SquareException('connection timed out');
      }
      return new UpdateCustomerResponse(['customer' => new Customer(['id' => 'CUST-UPDATED'])]);
    });
    $mock->method('create')->willReturnCallback(function (CreateCustomerRequest $request) {
      $this->created[] = $request;
      return new CreateCustomerResponse(['customer' => new Customer(['id' => 'CUST-NEW-' . count($this->created)])]);
    });
    $client->customers = $mock;
    $gateway = new CRM_Square_Gateway($this->processorConfig(), fn () => $client);

    return new class($gateway, $map) extends CRM_Square_Customers {

      /**
       * Square customer ID by contact ID.
       *
       * @var array
       */
      public array $map;

      public function __construct(CRM_Square_Gateway $gateway, array $map) {
        parent::__construct($gateway);
        $this->map = $map;
      }

      public function getSquareCustomerId($contactId) {
        return $this->map[(int) $contactId] ?? NULL;
      }

      protected function getMappedContactId(string $customerId): ?int {
        $contactId = array_search($customerId, $this->map, TRUE);
        return $contactId === FALSE ? NULL : (int) $contactId;
      }

      protected function saveSquareCustomerId($contactId, $customerId): ?string {
        // As square_customer_map's two unique keys do.
        if (!isset($this->map[(int) $contactId]) && $this->getMappedContactId($customerId) === NULL) {
          $this->map[(int) $contactId] = $customerId;
        }
        return $this->getSquareCustomerId($contactId);
      }

      protected function getContactDetails(int $contactID): ?array {
        return ['first_name' => 'Pat', 'last_name' => 'Doe', 'email' => 'pat@example.org'];
      }

    };
  }

}
