<?php

use Square\SquareClient;
use Square\Exceptions\SquareApiException;
use Square\Exceptions\SquareException;

/**
 * Square API access for one CiviCRM Square payment processor.
 *
 * Owns the processor's credentials and environment (sandbox or production):
 * it builds the Square SDK client, derives idempotency keys, and translates
 * SDK exceptions into CRM_Core_Exception — transient ones into
 * CRM_Core_Payment_SquareRetryableException.
 */
class CRM_Square_Gateway {

  /**
   * Payment-processor instance configuration (a civicrm_payment_processor row).
   *
   * @var array
   */
  protected array $paymentProcessor;

  /**
   * Returns the Square SDK client to use.
   *
   * @var callable
   */
  private $clientFactory;

  /**
   * Builds Square API access for one payment processor.
   *
   * @param array $paymentProcessor
   *   Row from civicrm_payment_processor.
   * @param callable|null $clientFactory
   *   Returns the \Square\SquareClient to use; defaults to createClient().
   */
  public function __construct(array $paymentProcessor, ?callable $clientFactory = NULL) {
    $this->paymentProcessor = $paymentProcessor;
    $this->clientFactory = $clientFactory ?? fn () => $this->createClient();
  }

  /**
   * ID of this payment processor (civicrm_payment_processor.id).
   */
  public function processorId(): int {
    return (int) ($this->paymentProcessor['id'] ?? 0);
  }

  /**
   * Whether this processor is in test/sandbox mode.
   */
  public function isTestMode(): bool {
    return !empty($this->paymentProcessor['is_test']);
  }

  /**
   * The Square SDK client for this processor.
   */
  public function client(): SquareClient {
    return ($this->clientFactory)();
  }

  /**
   * Build a Square SDK client from this processor's credentials.
   *
   * @return \Square\SquareClient
   */
  public function createClient(): SquareClient {
    return new SquareClient(
      token: $this->getAccessToken(),
      options: ['baseUrl' => $this->getApiBaseUrl()],
    );
  }

  /**
   * Get the Square Location ID from processor config.
   *
   * @return string
   *
   * @throws \CRM_Core_Exception
   */
  public function getLocationId(): string {
    $loc = trim($this->paymentProcessor['signature'] ?? '');
    if (empty($loc)) {
      throw new CRM_Core_Exception('Square location ID is not configured on this payment processor.');
    }
    return $loc;
  }

  /**
   * Get the Square access token from processor config.
   *
   * @return string
   *
   * @throws \CRM_Core_Exception
   */
  protected function getAccessToken() {

    return trim($this->paymentProcessor['password'] ?? '');
  }

  /**
   * Base URL for Square API, depending on mode and config.
   *
   * @return string
   */
  protected function getApiBaseUrl() {
    // Allow overriding via processor config if provided.
    if (!empty($this->paymentProcessor['url_api'])) {
      return rtrim($this->paymentProcessor['url_api'], '/');
    }

    // Fallback: use sensible defaults based on test/live.
    if ($this->isTestMode()) {
      return 'https://connect.squareupsandbox.com';
    }

    return 'https://connect.squareup.com';
  }

  /**
   * Build a retry-stable idempotency key unique to each processor and environment.
   */
  public function idempotencyKey(string $operation, string $reference): string {
    $processorId = (string) ($this->paymentProcessor['id'] ?? '0');
    $environment = $this->isTestMode() ? 'test' : 'live';
    return substr('civi-' . $operation . '-' . hash('sha256', implode(':', [
      $processorId,
      $environment,
      $reference,
    ])), 0, 45);
  }

  /**
   * Invoke the Square SDK client and translate its exceptions to CRM_Core_Exception.
   *
   * @param callable(\Square\SquareClient): mixed $call
   *
   * @return mixed
   *   Whatever $call returns (typically a Square SDK response object).
   *
   * @throws \CRM_Core_Exception
   */
  public function call(callable $call) {
    try {
      return $call($this->client());
    }
    catch (SquareApiException $e) {
      throw $this->apiError($e);
    }
    catch (SquareException $e) {
      // Transport-level failure (timeout, DNS, connection reset, etc.) —
      // there's no reason to believe retrying would fail again the same
      // way, so callers processing a queued webhook should retry rather
      // than give up permanently.
      throw new CRM_Core_Payment_SquareRetryableException('Square API request failed: ' . $e->getMessage());
    }
  }

  /**
   * Convert a Square SDK API exception to the message shape callers expect.
   *
   * HTTP 5xx and 429 are treated as transient (Square-side outage or rate
   * limiting) so webhook processing retries them; 4xx (other than 429)
   * indicates a malformed/rejected request that will fail identically on
   * retry, so it is treated as permanent.
   *
   * @param \Square\Exceptions\SquareApiException $e
   *
   * @return \CRM_Core_Exception
   */
  public function apiError(SquareApiException $e): CRM_Core_Exception {
    $errorDetails = [];
    foreach ($e->getErrors() as $err) {
      $code = $err->getCode() ?: 'UNKNOWN';
      $detail = $err->getDetail() ?? '';
      $errorDetails[] = "{$code}: {$detail}";
    }
    $statusCode = $e->getStatusCode();
    $msg = "Square API returned HTTP {$statusCode}.";
    if ($errorDetails) {
      $msg .= ' ' . implode(' | ', $errorDetails);
    }
    $exceptionClass = ($statusCode >= 500 || $statusCode === 429)
      ? CRM_Core_Payment_SquareRetryableException::class
      : CRM_Core_Exception::class;
    return new $exceptionClass($msg);
  }

}
