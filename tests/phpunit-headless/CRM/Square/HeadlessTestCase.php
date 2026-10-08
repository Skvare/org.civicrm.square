<?php

use Civi\Api4\Contact;
use Civi\Api4\Email;
use Civi\Api4\PaymentProcessor;
use Civi\Test;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;
use Square\SquareClient;

/**
 * Base for the headless tests: a real CiviCRM with this extension installed.
 *
 * Square itself is never called: tests give each Gateway a SquareClient
 * whose sub-clients they have mocked.
 */
abstract class CRM_Square_HeadlessTestCase extends TestCase implements HeadlessInterface, TransactionalInterface {

  /**
   * The Square payment processor (a civicrm_payment_processor row).
   *
   * @var array
   */
  protected array $processor;

  /**
   * @return \Civi\Test\CiviEnvBuilder
   */
  public function setUpHeadless() {
    return Test::headless()
      ->install(['mjwshared'])
      ->installMe(__DIR__)
      ->apply();
  }

  protected function setUp(): void {
    parent::setUp();
    $this->processor = PaymentProcessor::create(FALSE)
      ->setValues([
        'payment_processor_type_id:name' => 'Square',
        'name' => 'Square headless test',
        'is_test' => FALSE,
        'is_active' => TRUE,
        'user_name' => 'application-id',
        'password' => 'access-token',
        'signature' => 'LOCATION-1',
        'subject' => 'signature-key',
      ])
      ->execute()
      ->single();
  }

  /**
   * A Gateway for the test processor, using the given Square client.
   *
   * @param \Square\SquareClient $client
   *
   * @return \CRM_Square_Gateway
   */
  protected function gateway(SquareClient $client): CRM_Square_Gateway {
    return new CRM_Square_Gateway($this->processor, fn () => $client);
  }

  /**
   * A SquareClient that never reaches Square.
   *
   * @param array $subClients
   *   Mocked sub-clients, by SquareClient property name.
   *
   * @return \Square\SquareClient
   */
  protected function squareClient(array $subClients = []): SquareClient {
    $client = new SquareClient(token: 'test-token', options: ['baseUrl' => 'https://example.invalid']);
    foreach ($subClients as $property => $subClient) {
      $client->$property = $subClient;
    }
    return $client;
  }

  /**
   * Create an individual, optionally with a primary email.
   *
   * @param string $firstName
   * @param string|null $email
   *
   * @return int
   */
  protected function createContact(string $firstName, ?string $email = NULL): int {
    $contactId = (int) Contact::create(FALSE)
      ->setValues(['contact_type' => 'Individual', 'first_name' => $firstName, 'last_name' => 'Doe'])
      ->execute()
      ->single()['id'];
    if ($email) {
      Email::create(FALSE)
        ->setValues(['contact_id' => $contactId, 'email' => $email, 'is_primary' => TRUE])
        ->execute();
    }
    return $contactId;
  }

  /**
   * Map a Square customer to a contact for the test processor.
   *
   * @param int $contactId
   * @param string $customerId
   */
  protected function mapCustomer(int $contactId, string $customerId): void {
    CRM_Core_DAO::executeQuery(
      'INSERT INTO square_customer_map (contact_id, payment_processor_id, square_customer_id) VALUES (%1, %2, %3)',
      [1 => [$contactId, 'Integer'], 2 => [$this->processor['id'], 'Integer'], 3 => [$customerId, 'String']]
    );
  }

  /**
   * The test processor's customer mappings, as square_customer_id => contact_id.
   *
   * @return array
   */
  protected function customerMap(): array {
    $map = [];
    $dao = CRM_Core_DAO::executeQuery(
      'SELECT square_customer_id, contact_id FROM square_customer_map WHERE payment_processor_id = %1',
      [1 => [$this->processor['id'], 'Integer']]
    );
    while ($dao->fetch()) {
      $map[$dao->square_customer_id] = (int) $dao->contact_id;
    }
    return $map;
  }

}
