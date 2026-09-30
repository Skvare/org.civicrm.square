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
 * Plus the human-friendly card-decline message translation used by
 * createCardOnFile().
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
   * @dataProvider checkoutEntryPointProvider
   */
  public function testCardDeclineUsesCorePaymentFailureException(string $entryPoint): void {
    $paymentsMock = $this->createMock(PaymentsClient::class);
    $paymentsMock->method('create')->willThrowException(
      $this->apiException(400, [
        [
          'category' => 'PAYMENT_METHOD_ERROR',
          'code' => 'CARD_DECLINED',
          'detail' => 'Card was declined.',
        ],
      ])
    );

    $processor = $this->processorWithMockClient(['payments' => $paymentsMock]);

    $this->expectException(PaymentProcessorException::class);
    $this->expectExceptionMessageMatches('/CARD_DECLINED/');
    $params = ['token' => 'cnon:declined', 'amount' => '10.00', 'invoiceID' => 'inv-declined'];
    $processor->$entryPoint($params);
  }

  public static function checkoutEntryPointProvider(): array {
    return [['doPayment'], ['doDirectPayment']];
  }

  public function testCardOnFileDeclineUsesCorePaymentFailureException(): void {
    $cards = $this->createMock(CardsClient::class);
    $exception = $this->apiException(400, [
      [
        'category' => 'PAYMENT_METHOD_ERROR',
        'code' => 'CARD_DECLINED',
        'detail' => 'Card was declined.',
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
      $this->assertSame('Your card was declined. Please use a different card.', $e->getMessage());
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

  public function testTransportFailureKeepsItsRetryableException(): void {
    $paymentsMock = $this->createMock(PaymentsClient::class);
    $paymentsMock->method('create')->willThrowException(new SquareException('connection timed out'));

    $processor = $this->processorWithMockClient(['payments' => $paymentsMock]);

    $this->expectException(CRM_Core_Payment_SquareRetryableException::class);
    $this->expectExceptionMessage('Square API request failed: connection timed out');
    $params = ['token' => 'cnon:timeout', 'amount' => '10.00', 'invoiceID' => 'inv-timeout'];
    $processor->doPayment($params);
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
      'card declined' => ['CARD_DECLINED', 'declined'],
      'generic decline' => ['GENERIC_DECLINE', 'declined by the bank'],
      'invalid expiration' => ['INVALID_EXPIRATION', 'expiration date is invalid'],
      'cvv failure' => ['CVV_FAILURE', 'CVV security code is incorrect'],
      'address verification failure' => ['ADDRESS_VERIFICATION_FAILURE', 'ZIP/postal code did not match'],
      'insufficient funds' => ['INSUFFICIENT_FUNDS', 'insufficient funds'],
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
