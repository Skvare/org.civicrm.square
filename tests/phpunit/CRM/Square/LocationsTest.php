<?php

require_once dirname(__DIR__) . '/Core/Payment/Square/SquareUnitTestCase.php';

use Square\Exceptions\SquareApiException;
use Square\Locations\LocationsClient;
use Square\SquareClient;

/**
 * Looking up the Square location a processor takes payments at.
 */
class CRM_Square_LocationsTest extends CRM_Core_Payment_Square_SquareUnitTestCase {

  protected function setUp(): void {
    parent::setUp();
    Civi::$cache = [];
  }

  public function testLocationDetailsAreReturnedAndCached(): void {
    $client = $this->locationsClient('cad', 'ACTIVE');
    $client->expects($this->once())->method('get');
    $locations = $this->locations($client);

    $location = $locations->get();
    $this->assertSame('CAD', $location['currency']);
    $this->assertSame('ACTIVE', $location['status']);
    $this->assertSame('Main', $location['name']);
    $this->assertSame('CAD', $locations->getCurrency());
  }

  public function testRefreshAsksSquareAgain(): void {
    $client = $this->locationsClient();
    $client->expects($this->exactly(2))->method('get');
    $locations = $this->locations($client);

    $locations->get();
    $locations->get(TRUE);
  }

  public function testEachProcessorAndEnvironmentHasItsOwnCacheEntry(): void {
    $this->locations($this->locationsClient('USD'))->get();
    $this->assertSame('GBP', $this->locations($this->locationsClient('GBP'), ['is_test' => TRUE])->getCurrency());
  }

  public function testSquareRefusingTheLookupIsAnError(): void {
    $client = $this->createMock(LocationsClient::class);
    $client->method('get')->willThrowException(new SquareApiException('API request failed', 401, json_encode([
      'errors' => [
        [
          'category' => 'AUTHENTICATION_ERROR',
          'code' => 'UNAUTHORIZED',
          'detail' => 'This request could not be authorized.',
        ],
      ],
    ])));

    $this->expectException(CRM_Core_Exception::class);
    $this->expectExceptionMessage('UNAUTHORIZED');
    $this->locations($client)->get();
  }

  /**
   * Location lookup for processor 42 against a mocked Locations API.
   *
   * @param \Square\Locations\LocationsClient $client
   * @param array $configOverrides
   *
   * @return \CRM_Square_Locations
   */
  private function locations(LocationsClient $client, array $configOverrides = []): CRM_Square_Locations {
    $squareClient = new SquareClient(token: 'test-token', options: ['baseUrl' => 'https://example.invalid']);
    $squareClient->locations = $client;
    return new CRM_Square_Locations(new CRM_Square_Gateway($this->processorConfig($configOverrides), fn () => $squareClient));
  }

}
