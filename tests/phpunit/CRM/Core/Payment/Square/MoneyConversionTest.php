<?php

require_once __DIR__ . '/SquareUnitTestCase.php';

use Square\Payments\PaymentsClient;
use Square\Payments\Requests\CreatePaymentRequest;
use Square\Refunds\RefundsClient;
use Square\Types\CreatePaymentResponse;
use Square\Types\Payment;

/**
 * Dollars-to-cents conversion and the amount guards around it.
 */
class CRM_Core_Payment_Square_MoneyConversionTest extends CRM_Core_Payment_Square_SquareUnitTestCase {

  /**
   * @dataProvider amountProvider
   */
  public function testOneTimePaymentConvertsDollarsToCents(string $dollars, int $expectedCents): void {
    $captured = NULL;
    $paymentsMock = $this->createMock(PaymentsClient::class);
    $paymentsMock->method('create')->willReturnCallback(function (CreatePaymentRequest $request) use (&$captured) {
      $captured = $request;
      return new CreatePaymentResponse(['payment' => new Payment(['id' => 'pay_x', 'status' => 'COMPLETED'])]);
    });

    $processor = $this->processorWithMockClient(['payments' => $paymentsMock]);
    $params = ['token' => 'cnon:x', 'amount' => $dollars, 'invoiceID' => 'inv-money'];
    $processor->doPayment($params);

    $this->assertSame($expectedCents, $captured->getAmountMoney()->getAmount());
  }

  public static function amountProvider(): array {
    return [
      'simple' => ['12.34', 1234],
      'small fraction' => ['0.10', 10],
      'rounds half up' => ['19.995', 2000],
      'whole dollars' => ['100', 10000],
    ];
  }

  public function testZeroAmountOneTimePaymentShortCircuitsWithoutCallingSquare(): void {
    $paymentsMock = $this->createMock(PaymentsClient::class);
    $paymentsMock->expects($this->never())->method('create');

    $processor = $this->processorWithMockClient(['payments' => $paymentsMock]);
    $params = ['token' => 'cnon:zero', 'amount' => '0.00'];
    $result = $processor->doPayment($params);

    // Reported complete, as CRM_Core_Payment::doPayment() does for $0.
    $this->assertSame('Completed', $result['payment_status']);
    $this->assertSame(1, $result['payment_status_id']);
    $this->assertArrayNotHasKey('trxn_id', $result);
  }

  public function testRefundRejectsZeroAmount(): void {
    $refundsMock = $this->createMock(RefundsClient::class);
    $refundsMock->expects($this->never())->method('refundPayment');

    $processor = $this->processorWithMockClient(['refunds' => $refundsMock]);

    $this->expectException(CRM_Core_Exception::class);
    $this->expectExceptionMessage('Refund amount must be greater than zero.');
    $params = ['trxn_id' => 'pay_1', 'amount' => '0.00'];
    $processor->doRefund($params);
  }

  public function testRefundRejectsNegativeAmount(): void {
    $refundsMock = $this->createMock(RefundsClient::class);
    $refundsMock->expects($this->never())->method('refundPayment');

    $processor = $this->processorWithMockClient(['refunds' => $refundsMock]);

    $this->expectException(CRM_Core_Exception::class);
    $params = ['trxn_id' => 'pay_1', 'amount' => '-5.00'];
    $processor->doRefund($params);
  }

}
