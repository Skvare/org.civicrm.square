<?php

use PHPUnit\Framework\TestCase;
use Square\SquareClient;

/**
 * Shared helpers for mocked-Square-client unit tests.
 *
 * No CiviCRM DB or full bootstrap is available in this test suite (see
 * tests/phpunit/bootstrap.php) — only exercise code paths that don't touch
 * CRM_Core_DAO, Civi\Api4\*, or Civi::settings()/Civi::log(). In practice
 * that means: omit 'contactID'/'contact_id' from $params (skips
 * getSquareCustomerId()/PaymentToken creation) and never call
 * doRecurPayment()/ensureSquareCustomer() directly — those are covered by
 * the DB-backed integration suite instead.
 */
abstract class CRM_Core_Payment_Square_SquareUnitTestCase extends TestCase {

  /**
   * @param array $overrides
   *   Fields to override on top of the default fake processor config.
   *
   * @return array
   *   A fake civicrm_payment_processor row.
   */
  protected function processorConfig(array $overrides = []): array {
    return array_replace([
      'id' => 42,
      'is_test' => FALSE,
      'user_name' => 'application-id',
      'password' => 'access-token',
      'signature' => 'location-id',
      'subject' => 'signature-key',
    ], $overrides);
  }

  /**
   * Build a live CRM_Core_Payment_Square with a mocked SquareClient.
   *
   * Its buildSquareClient() returns a real (network-inert) SquareClient
   * with the given sub-client properties swapped for PHPUnit mocks, e.g.
   *   $this->processorWithMockClient([
   *     'payments' => $this->createMock(PaymentsClient::class),
   *   ])
   *
   * @param array<string, \PHPUnit\Framework\MockObject\MockObject> $mockSubClients
   *   Keyed by SquareClient property name (payments, cards, refunds, etc).
   * @param array $configOverrides
   *   Fields to override on top of the default fake processor config.
   *
   * @return CRM_Core_Payment_Square
   */
  protected function processorWithMockClient(array $mockSubClients, array $configOverrides = []): CRM_Core_Payment_Square {
    $config = $this->processorConfig($configOverrides);

    $client = new SquareClient(token: 'test-token', options: ['baseUrl' => 'https://example.invalid']);
    foreach ($mockSubClients as $property => $mock) {
      $client->$property = $mock;
    }

    return new class($config, $client) extends CRM_Core_Payment_Square {

      /**
       * @var \Square\SquareClient
       */
      private SquareClient $mockClient;

      /**
       * @param array $paymentProcessor
       * @param \Square\SquareClient $mockClient
       */
      public function __construct(array &$paymentProcessor, SquareClient $mockClient) {
        parent::__construct('live', $paymentProcessor);
        $this->mockClient = $mockClient;
      }

      /**
       * @return \Square\SquareClient
       */
      protected function buildSquareClient(): SquareClient {
        return $this->mockClient;
      }

    };
  }

  /**
   * Expose a protected/private method or property for direct unit testing.
   *
   * @param object $object
   * @param string $method
   * @param array $args
   *
   * @return mixed
   *   Whatever the invoked method returns.
   */
  protected function callMethod(object $object, string $method, array $args = []) {
    // No setAccessible(): unnecessary since PHP 8.1, deprecated in PHP 8.5.
    return (new ReflectionMethod($object, $method))->invokeArgs($object, $args);
  }

}
