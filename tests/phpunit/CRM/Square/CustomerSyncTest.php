<?php

require_once dirname(__DIR__) . '/Core/Payment/Square/SquareUnitTestCase.php';

use Square\Customers\CustomersClient;
use Square\Customers\Requests\UpdateCustomerRequest;
use Square\Exceptions\SquareException;
use Square\SquareClient;
use Square\Types\UpdateCustomerResponse;

/**
 * Keeping Square customers' names and emails in step with their contacts.
 */
class CRM_Square_CustomerSyncTest extends CRM_Core_Payment_Square_SquareUnitTestCase {

  /**
   * Customer updates sent to (mocked) Square.
   *
   * @var \Square\Customers\Requests\UpdateCustomerRequest[]
   */
  private array $updates = [];

  protected function setUp(): void {
    parent::setUp();
    Civi::$cache = [];
    Civi::$logged = [];
    $this->updates = [];
  }

  public function testCustomerIsUpdatedFromTheContact(): void {
    $customers = $this->customers();

    $contact = ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ada@example.org'];
    $this->assertTrue($customers->syncFromContact('CUST-1', 12, $contact));

    $this->assertCount(1, $this->updates);
    $this->assertSame('CUST-1', $this->updates[0]->getCustomerId());
    $this->assertSame('Ada', $this->updates[0]->getGivenName());
    $this->assertSame('Lovelace', $this->updates[0]->getFamilyName());
    $this->assertSame('ada@example.org', $this->updates[0]->getEmailAddress());
    $this->assertSame('12', $this->updates[0]->getReferenceId());
  }

  public function testUnchangedDetailsAreNotSentAgain(): void {
    $customers = $this->customers();
    $contact = ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ada@example.org'];

    $customers->syncFromContact('CUST-1', 12, $contact);
    $this->assertFalse($customers->syncFromContact('CUST-1', 12, $contact));
    $this->assertCount(1, $this->updates);

    $this->assertTrue($customers->syncFromContact('CUST-1', 12, ['email' => 'ada@new.example.org'] + $contact));
    $this->assertCount(2, $this->updates);
  }

  public function testFailedUpdateIsTriedAgainNextTime(): void {
    $customers = $this->customers(new SquareException('Connection timed out'));
    $contact = ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ada@example.org'];

    $this->assertFalse($customers->syncFromContact('CUST-1', 12, $contact));
    $this->assertSame([], Civi::$cache, 'Nothing remembered, so the next edit tries again.');
  }

  public function testEachMappedCustomerOfAnActiveProcessorIsUpdated(): void {
    $synced = [];
    $sync = new class($synced) extends CRM_Square_CustomerSync {

      /**
       * @var array
       */
      private array $synced;

      /**
       * @param array $synced
       */
      public function __construct(array &$synced) {
        $this->synced = &$synced;
      }

      /**
       * @param int[] $contactIds
       *
       * @return array[]
       */
      protected function getMappings(array $contactIds): array {
        return [
          ['contact_id' => 12, 'payment_processor_id' => 42, 'square_customer_id' => 'CUST-LIVE'],
          ['contact_id' => 12, 'payment_processor_id' => 43, 'square_customer_id' => 'CUST-TEST'],
          ['contact_id' => 13, 'payment_processor_id' => 99, 'square_customer_id' => 'CUST-GONE'],
          ['contact_id' => 14, 'payment_processor_id' => 42, 'square_customer_id' => 'CUST-DELETED'],
        ];
      }

      /**
       * @param int[] $contactIds
       *
       * @return array[]
       */
      protected function getContacts(array $contactIds): array {
        // Contact 14 is in the trash.
        return [
          12 => ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ada@example.org'],
          13 => ['first_name' => 'Grace', 'last_name' => 'Hopper', 'email' => 'grace@example.org'],
        ];
      }

      /**
       * @param int $processorId
       *
       * @return \CRM_Core_Payment_Square|null
       */
      protected function getProcessor(int $processorId): ?CRM_Core_Payment_Square {
        // Processor 99 was disabled.
        if ($processorId === 99) {
          return NULL;
        }
        $synced = &$this->synced;
        $config = ['id' => $processorId];
        return new class($config, $synced) extends CRM_Core_Payment_Square {

          /**
           * @var array
           */
          private array $synced;

          /**
           * @param array $config
           * @param array $synced
           */
          public function __construct(array $config, array &$synced) {
            parent::__construct('live', $config);
            $this->synced = &$synced;
          }

          /**
           * @param string $customerId
           * @param int $contactId
           * @param array $contact
           *
           * @return bool
           */
          public function syncSquareCustomer(string $customerId, int $contactId, array $contact): bool {
            $this->synced[] = [$this->_paymentProcessor['id'], $customerId, $contactId, $contact['email']];
            return TRUE;
          }

        };
      }

    };

    $this->assertSame(2, $sync->sync([12, 13, 14, 12]));
    $this->assertSame([
      [42, 'CUST-LIVE', 12, 'ada@example.org'],
      [43, 'CUST-TEST', 12, 'ada@example.org'],
    ], $synced);
  }

  /**
   * Square customers for processor 42, against a mocked Customers API.
   *
   * @param \Throwable|null $failure
   *   What the update throws instead of succeeding.
   *
   * @return \CRM_Square_Customers
   */
  private function customers(?\Throwable $failure = NULL): CRM_Square_Customers {
    $customersClient = $this->createMock(CustomersClient::class);
    $customersClient->method('update')->willReturnCallback(function (UpdateCustomerRequest $request) use ($failure) {
      if ($failure) {
        throw $failure;
      }
      $this->updates[] = $request;
      return new UpdateCustomerResponse([]);
    });
    $client = new SquareClient(token: 'test-token', options: ['baseUrl' => 'https://example.invalid']);
    $client->customers = $customersClient;
    return new CRM_Square_Customers(new CRM_Square_Gateway($this->processorConfig(), fn () => $client));
  }

}
