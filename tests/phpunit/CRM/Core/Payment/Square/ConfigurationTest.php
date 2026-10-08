<?php

require_once __DIR__ . '/SquareUnitTestCase.php';

use Square\SquareClient;

/**
 * Payment-processor configuration validation.
 *
 * Plus environment (sandbox vs. live) resolution.
 */
class CRM_Core_Payment_Square_ConfigurationTest extends CRM_Core_Payment_Square_SquareUnitTestCase {

  private function processor(array $overrides = []): CRM_Core_Payment_Square {
    $config = $this->processorConfig($overrides);
    return new CRM_Core_Payment_Square('live', $config);
  }

  public function testCheckConfigReportsEveryMissingCredential(): void {
    $processor = $this->processor([
      'user_name' => '',
      'password' => '',
      'signature' => '',
      'subject' => '',
    ]);

    $error = $processor->checkConfig();

    $this->assertStringContainsString('Application ID', $error);
    $this->assertStringContainsString('Access Token', $error);
    $this->assertStringContainsString('Location ID', $error);
    $this->assertStringContainsString('Webhook Signature Key', $error);
  }

  public function testCheckConfigAcceptsCompleteCredentials(): void {
    $this->assertNull($this->processor()->checkConfig());
  }

  public function testCheckConfigReportsOnlyTheFieldsActuallyMissing(): void {
    $error = $this->processor(['subject' => ''])->checkConfig();
    $this->assertStringContainsString('Webhook Signature Key', $error);
    $this->assertStringNotContainsString('Access Token', $error);
  }

  public function testGetLocationIdThrowsWhenSignatureIsEmpty(): void {
    $processor = $this->processor(['signature' => '']);
    $this->expectException(CRM_Core_Exception::class);
    $this->callMethod($processor, 'getLocationId');
  }

  public function testGetLocationIdTrimsWhitespace(): void {
    $processor = $this->processor(['signature' => '  location-id  ']);
    $this->assertSame('location-id', $this->callMethod($processor, 'getLocationId'));
  }

  public function testApiBaseUrlDefaultsToSandboxInTestMode(): void {
    $gateway = new CRM_Square_Gateway($this->processorConfig(['is_test' => TRUE]));
    $this->assertSame('https://connect.squareupsandbox.com', $this->callMethod($gateway, 'getApiBaseUrl'));
  }

  public function testApiBaseUrlDefaultsToLiveOutsideTestMode(): void {
    $gateway = new CRM_Square_Gateway($this->processorConfig(['is_test' => FALSE]));
    $this->assertSame('https://connect.squareup.com', $this->callMethod($gateway, 'getApiBaseUrl'));
  }

  public function testApiBaseUrlHonorsConfiguredOverrideEvenInTestMode(): void {
    $gateway = new CRM_Square_Gateway($this->processorConfig([
      'is_test' => TRUE,
      'url_api' => 'https://example-override.test/',
    ]));
    $this->assertSame('https://example-override.test', $this->callMethod($gateway, 'getApiBaseUrl'));
  }

  public function testBuildSquareClientReturnsASquareClientForEitherEnvironment(): void {
    $this->assertInstanceOf(SquareClient::class, $this->callMethod($this->processor(['is_test' => TRUE]), 'buildSquareClient'));
    $this->assertInstanceOf(SquareClient::class, $this->callMethod($this->processor(['is_test' => FALSE]), 'buildSquareClient'));
  }

}
