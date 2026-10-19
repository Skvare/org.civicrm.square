<?php

require_once __DIR__ . '/SquareUnitTestCase.php';

use Square\Types\Error;
use Square\Exceptions\SquareApiException;
use Square\Exceptions\SquareException;
use Square\Payments\PaymentsClient;
use Square\Cards\CardsClient;
use Civi\Payment\Exception\PaymentProcessorException;

/**
 * Translation of Square SDK exceptions into CRM_Core_Exception.
 *
 * Plus the human-friendly card-decline messages for payments and cards on file.
 */
class CRM_Core_Payment_Square_ExceptionHandlingTest extends CRM_Core_Payment_Square_SquareUnitTestCase {

  private function apiException(int $statusCode, array $errors): SquareApiException {
    return new SquareApiException('API request failed', $statusCode, json_encode(['errors' => $errors]));
  }

  public function testSquareApiErrorFormatsHttpCodeAndErrorDetails(): void {
    $exception = $this->apiException(400, [
      [
        'category' => 'INVALID_REQUEST_ERROR',
        'code' => 'VALUE_TOO_LONG',
        'detail' => 'idempotency_key must not be greater than 45 length',
      ],
    ]);

    $result = (new CRM_Square_Gateway($this->processorConfig()))->apiError($exception);

    $this->assertInstanceOf(CRM_Core_Exception::class, $result);
    $this->assertStringContainsString('Square API returned HTTP 400.', $result->getMessage());
    $this->assertStringContainsString('VALUE_TOO_LONG: idempotency_key must not be greater than 45 length', $result->getMessage());
  }

  public function testSquareApiErrorJoinsMultipleErrors(): void {
    $exception = $this->apiException(400, [
      ['category' => 'INVALID_REQUEST_ERROR', 'code' => 'BAD_REQUEST', 'detail' => 'first problem'],
      ['category' => 'INVALID_REQUEST_ERROR', 'code' => 'ALSO_BAD', 'detail' => 'second problem'],
    ]);

    $message = (new CRM_Square_Gateway($this->processorConfig()))->apiError($exception)->getMessage();

    $this->assertStringContainsString('BAD_REQUEST: first problem', $message);
    $this->assertStringContainsString('ALSO_BAD: second problem', $message);
  }

  /**
   * @dataProvider checkoutCardErrorProvider
   */
  public function testCardDeclineUsesCorePaymentFailureException(string $entryPoint, string $code, string $message): void {
    $paymentsMock = $this->createMock(PaymentsClient::class);
    $exception = $this->apiException(400, [
      [
        'category' => 'PAYMENT_METHOD_ERROR',
        'code' => $code,
        'detail' => "Authorization error: '{$code}'",
      ],
    ]);
    $paymentsMock->method('create')->willThrowException($exception);

    $processor = $this->processorWithMockClient(['payments' => $paymentsMock]);

    $params = ['token' => 'cnon:declined', 'amount' => '10.00', 'invoiceID' => 'inv-declined'];
    try {
      $processor->$entryPoint($params);
      $this->fail('A declined card must throw.');
    }
    catch (PaymentProcessorException $e) {
      $this->assertSame($message, $e->getMessage());
      $this->assertSame($exception, $e->getPrevious());
    }
  }

  public static function checkoutCardErrorProvider(): array {
    $cases = [];
    foreach (['doPayment', 'doDirectPayment'] as $entryPoint) {
      foreach (self::cardErrorCodeProvider() as $name => [$code, $message]) {
        $cases[$entryPoint . ': ' . $name] = [$entryPoint, $code, $message];
      }
    }
    return $cases;
  }

  /**
   * @dataProvider cardErrorCodeProvider
   */
  public function testCardOnFileDeclineUsesCorePaymentFailureException(string $code, string $message): void {
    $cards = $this->createMock(CardsClient::class);
    $exception = $this->apiException(400, [
      [
        'category' => 'PAYMENT_METHOD_ERROR',
        'code' => $code,
        'detail' => "Authorization error: '{$code}'",
      ],
    ]);
    $cards->method('create')->willThrowException($exception);
    $processor = $this->processorWithMockClient(['cards' => $cards]);

    try {
      // Recurring checkout saves the card through this same entry point.
      $processor->createCardOnFile('customer-1', 'cnon:declined');
      $this->fail('A declined card must throw.');
    }
    catch (PaymentProcessorException $e) {
      $this->assertSame($message, $e->getMessage());
      $this->assertSame($exception, $e->getPrevious());
    }
  }

  /**
   * @dataProvider nonDeclineErrorProvider
   */
  public function testOtherApiFailuresDoNotTriggerPaymentFailureCleanup(int $status, string $category, string $expectedClass): void {
    $exception = $this->apiException($status, [
      [
        'category' => $category,
        'code' => 'TEST_ERROR',
        'detail' => 'Request failed.',
      ],
    ]);
    $result = (new CRM_Square_Gateway($this->processorConfig()))->apiError($exception);

    $this->assertSame($expectedClass, get_class($result));
    $this->assertNotInstanceOf(PaymentProcessorException::class, $result);
  }

  public static function nonDeclineErrorProvider(): array {
    return [
      'invalid request' => [400, 'INVALID_REQUEST_ERROR', CRM_Core_Exception::class],
      'credentials' => [401, 'AUTHENTICATION_ERROR', CRM_Core_Exception::class],
      'rate limit' => [429, 'RATE_LIMIT_ERROR', CRM_Core_Payment_SquareRetryableException::class],
      'server failure' => [503, 'API_ERROR', CRM_Core_Payment_SquareRetryableException::class],
      'uncertain payment outcome' => [500, 'PAYMENT_METHOD_ERROR', CRM_Core_Payment_SquareRetryableException::class],
    ];
  }

  public function testUnconfirmedChargeKeepsTheContributionPageContributionPending(): void {
    $paymentsMock = $this->createMock(PaymentsClient::class);
    $paymentsMock->method('create')->willThrowException(new SquareException('connection timed out'));

    $processor = $this->processorWithMockClient(['payments' => $paymentsMock]);
    $params = ['token' => 'cnon:timeout', 'amount' => '10.00', 'invoiceID' => 'inv-timeout'];

    try {
      $processor->doPayment($params);
      $this->fail('An unconfirmed charge must throw.');
    }
    catch (PaymentProcessorException $e) {
      $this->fail('Square may have charged the card: CiviCRM must not mark the contribution Failed.');
    }
    catch (CRM_Core_Payment_SquareOutcomeUnknownException $e) {
      $this->assertStringContainsString('could not confirm', $e->getMessage());
      $this->assertInstanceOf(CRM_Core_Payment_SquareRetryableException::class, $e->getPrevious());
    }
    $this->assertLogged('error', 'could not confirm the outcome of payment inv-timeout');
  }

  public function testUnconfirmedChargeAtEventRegistrationIsAPaymentFailure(): void {
    $paymentsMock = $this->createMock(PaymentsClient::class);
    $paymentsMock->method('create')->willThrowException($this->apiException(503, [
      ['category' => 'API_ERROR', 'code' => 'SERVICE_UNAVAILABLE', 'detail' => 'Try again.'],
    ]));

    $processor = $this->processorWithMockClient(['payments' => $paymentsMock]);
    $params = ['token' => 'cnon:503', 'amount' => '10.00', 'invoiceID' => 'inv-event'];

    // Event registration only handles PaymentProcessorException; anything
    // else is a fatal error page for the registrant.
    $this->expectException(PaymentProcessorException::class);
    $this->expectExceptionMessage('could not confirm');
    $processor->doPayment($params, 'event');
  }

  public function testRejectedRequestIsAPaymentFailure(): void {
    $paymentsMock = $this->createMock(PaymentsClient::class);
    $paymentsMock->method('create')->willThrowException($this->apiException(400, [
      ['category' => 'INVALID_REQUEST_ERROR', 'code' => 'CARD_TOKEN_USED', 'detail' => 'Card nonce already used.'],
    ]));

    $processor = $this->processorWithMockClient(['payments' => $paymentsMock]);
    $params = ['token' => 'cnon:used', 'amount' => '10.00', 'invoiceID' => 'inv-used'];

    // Square refused the request, so nothing was charged: CiviCRM's checkout
    // may clean up as for a decline.
    try {
      $processor->doPayment($params);
      $this->fail('A rejected request must throw.');
    }
    catch (PaymentProcessorException $e) {
      $this->assertStringNotContainsString('CARD_TOKEN_USED', $e->getMessage());
      $this->assertStringContainsString('CARD_TOKEN_USED', $e->getPrevious()->getMessage());
    }
  }

  /**
   * Assert a message containing $needle was logged at $level.
   */
  private function assertLogged(string $level, string $needle): void {
    foreach (Civi::$logged as [$loggedLevel, $message]) {
      if ($loggedLevel === $level && str_contains($message, $needle)) {
        $this->addToAssertionCount(1);
        return;
      }
    }
    $this->fail("Nothing containing '{$needle}' was logged at level {$level}.");
  }

  /**
   * @dataProvider cardErrorCodeProvider
   */
  public function testTranslateSquareCardErrorHumanMessages(string $code, string $expectedSubstring): void {
    $error = new Error(['category' => 'PAYMENT_METHOD_ERROR', 'code' => $code]);
    $message = $this->callMethod(new CRM_Square_Customers(new CRM_Square_Gateway($this->processorConfig())), 'translateSquareCardError', [[$error]]);
    $this->assertStringContainsString($expectedSubstring, $message);
  }

  public static function cardErrorCodeProvider(): array {
    return [
      'card declined' => ['CARD_DECLINED', 'Your card was declined. Please use a different card.'],
      'generic decline' => ['GENERIC_DECLINE', 'The card was declined by the bank.'],
      'invalid expiration' => ['INVALID_EXPIRATION', 'The card expiration date is invalid.'],
      'cvv failure' => ['CVV_FAILURE', 'The CVV security code is incorrect.'],
      'address verification failure' => [
        'ADDRESS_VERIFICATION_FAILURE',
        'The billing ZIP/postal code did not match the card.',
      ],
      'insufficient funds' => ['INSUFFICIENT_FUNDS', 'The card has insufficient funds.'],
    ];
  }

  public function testTranslateSquareCardErrorFallsBackToDetailForUnknownCodes(): void {
    $error = new Error([
      'category' => 'PAYMENT_METHOD_ERROR',
      'code' => 'SOME_NEW_CODE',
      'detail' => 'A brand new failure reason.',
    ]);
    $message = $this->callMethod(new CRM_Square_Customers(new CRM_Square_Gateway($this->processorConfig())), 'translateSquareCardError', [[$error]]);
    $this->assertSame('A brand new failure reason.', $message);
  }

  public function testTranslateSquareCardErrorFallsBackToGenericMessageWithNoDetail(): void {
    $error = new Error(['category' => 'PAYMENT_METHOD_ERROR', 'code' => 'SOME_NEW_CODE']);
    $message = $this->callMethod(new CRM_Square_Customers(new CRM_Square_Gateway($this->processorConfig())), 'translateSquareCardError', [[$error]]);
    $this->assertSame('The card could not be processed.', $message);
  }

}
