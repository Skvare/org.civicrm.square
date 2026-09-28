<?php

require_once __DIR__ . '/SquareUnitTestCase.php';

use Square\Types\Money;
use Square\Cards\Requests\CreateCardRequest;
use Square\Cards\CardsClient;
use Square\Payments\PaymentsClient;
use Square\Payments\Requests\CreatePaymentRequest;
use Square\Refunds\RefundsClient;
use Square\Types\Card;
use Square\Types\CreateCardResponse;
use Square\Types\CreatePaymentResponse;
use Square\Types\Payment;
use Square\Types\PaymentRefund;
use Square\Types\RefundPaymentResponse;

/**
 * Asserts the exact request objects CRM_Core_Payment_Square builds.
 *
 * Field-by-field, for each Square SDK call, not just "it didn't throw".
 */
class CRM_Core_Payment_Square_RequestShapeTest extends CRM_Core_Payment_Square_SquareUnitTestCase {

  public function testOneTimePaymentRequestShape(): void {
    /** @var \Square\Payments\Requests\CreatePaymentRequest|null $captured */
    $captured = NULL;
    $paymentsMock = $this->createMock(PaymentsClient::class);
    $paymentsMock->method('create')->willReturnCallback(function (CreatePaymentRequest $request) use (&$captured) {
      $captured = $request;
      return new CreatePaymentResponse(['payment' => new Payment(['id' => 'pay_1', 'status' => 'COMPLETED'])]);
    });

    $processor = $this->processorWithMockClient(['payments' => $paymentsMock]);
    $params = [
      'square_payment_token' => 'cnon:one-time-token',
      'amount' => '12.34',
      'currency' => 'USD',
      'invoiceID' => 'inv-42',
      // Deliberately no contactID/contact_id — see class docblock on
      // SquareUnitTestCase for why.
    ];

    $result = $processor->doPayment($params);

    $this->assertNotNull($captured);
    $this->assertSame('cnon:one-time-token', $captured->getSourceId());
    $this->assertSame(1234, $captured->getAmountMoney()->getAmount());
    $this->assertSame('USD', $captured->getAmountMoney()->getCurrency());
    $this->assertSame('location-id', $captured->getLocationId());
    $this->assertSame('inv-42', $captured->getReferenceId());
    $this->assertNull($captured->getCustomerId(), 'No contactID was supplied, so no customer lookup/attachment should occur.');
    $this->assertNotEmpty($captured->getIdempotencyKey());
    $this->assertLessThanOrEqual(45, strlen($captured->getIdempotencyKey()));

    $this->assertSame('pay_1', $result['trxn_id']);
  }

  public function testOneTimePaymentOmitsReferenceIdWhenNoInvoiceId(): void {
    $captured = NULL;
    $paymentsMock = $this->createMock(PaymentsClient::class);
    $paymentsMock->method('create')->willReturnCallback(function (CreatePaymentRequest $request) use (&$captured) {
      $captured = $request;
      return new CreatePaymentResponse(['payment' => new Payment(['id' => 'pay_2', 'status' => 'COMPLETED'])]);
    });

    $processor = $this->processorWithMockClient(['payments' => $paymentsMock]);
    $params = ['token' => 'cnon:no-invoice', 'amount' => '5.00', 'contributionID' => 17];
    $processor->doPayment($params);

    $this->assertNull($captured->getReferenceId());
  }

  public function testOneTimePaymentFailsClosedWithoutACheckoutReference(): void {
    $paymentsMock = $this->createMock(PaymentsClient::class);
    $paymentsMock->expects($this->never())->method('create');

    $processor = $this->processorWithMockClient(['payments' => $paymentsMock]);
    $params = ['token' => 'cnon:no-reference', 'amount' => '5.00'];

    // A retry could not be recognized without one, so it is never charged.
    $this->expectException(CRM_Core_Exception::class);
    $this->expectExceptionMessage('without a CiviCRM invoice or contribution reference');
    $processor->doPayment($params);
  }

  public function testAuthorizedButUncapturedPaymentIsReportedPending(): void {
    $paymentsMock = $this->createMock(PaymentsClient::class);
    $paymentsMock->method('create')->willReturn(
      new CreatePaymentResponse(['payment' => new Payment(['id' => 'pay_held', 'status' => 'APPROVED'])])
    );

    $processor = $this->processorWithMockClient(['payments' => $paymentsMock]);
    $params = ['token' => 'cnon:held', 'amount' => '5.00', 'invoiceID' => 'inv-held'];
    $result = $processor->doPayment($params);

    $this->assertSame('Pending', $result['payment_status']);
    $this->assertSame(2, $result['payment_status_id']);
    $this->assertSame('pay_held', $result['trxn_id']);
  }

  public function testRefundRequestShape(): void {
    $captured = NULL;
    $refundsMock = $this->createMock(RefundsClient::class);
    $refundsMock->method('refundPayment')->willReturnCallback(function ($request) use (&$captured) {
      $captured = $request;
      return new RefundPaymentResponse([
        'refund' => new PaymentRefund([
          'id' => 'refund_1',
          'status' => 'COMPLETED',
          'amountMoney' => new Money(['amount' => 500, 'currency' => 'USD']),
        ]),
      ]);
    });

    $processor = $this->processorWithMockClient(['refunds' => $refundsMock]);
    $params = ['trxn_id' => 'pay_99', 'amount' => '5.00', 'currency' => 'USD'];
    $processor->doRefund($params);

    $this->assertSame('pay_99', $captured->getPaymentId());
    $this->assertSame(500, $captured->getAmountMoney()->getAmount());
    $this->assertNotEmpty($captured->getIdempotencyKey());
    $this->assertLessThanOrEqual(45, strlen($captured->getIdempotencyKey()));
  }

  public function testCreateCardOnFileRequestShapeWithBillingAddress(): void {
    $captured = NULL;
    $cardsMock = $this->createMock(CardsClient::class);
    $cardsMock->method('create')->willReturnCallback(function (CreateCardRequest $request) use (&$captured) {
      $captured = $request;
      return new CreateCardResponse(['card' => new Card(['id' => 'card_1', 'last4' => '1111'])]);
    });

    $processor = $this->processorWithMockClient(['cards' => $cardsMock]);
    $cardId = $processor->createCardOnFile('cust_1', 'cnon:card-nonce', [
      'billing_address' => [
        'street_address' => '123 Main St',
        'city' => 'Crossville',
        'state' => 'TN',
        'postal_code' => '38555',
        'country' => 'United States',
      ],
      // No contactId (4th arg omitted) -> skips PaymentToken creation, which
      // needs a real CiviCRM DB unavailable in this suite.
    ]);

    $this->assertSame('card_1', $cardId);
    $this->assertSame('cnon:card-nonce', $captured->getSourceId());
    $this->assertSame('cust_1', $captured->getCard()->getCustomerId());
    $billingAddress = $captured->getCard()->getBillingAddress();
    $this->assertSame('123 Main St', $billingAddress->getAddressLine1());
    $this->assertSame('Crossville', $billingAddress->getLocality());
    $this->assertSame('TN', $billingAddress->getAdministrativeDistrictLevel1());
    $this->assertSame('38555', $billingAddress->getPostalCode());
    // mapCountryCode() normalizes "United States" -> "US".
    $this->assertSame('US', $billingAddress->getCountry());
  }

  public function testCreateCardOnFileOmitsBillingAddressWhenNotSupplied(): void {
    $captured = NULL;
    $cardsMock = $this->createMock(CardsClient::class);
    $cardsMock->method('create')->willReturnCallback(function (CreateCardRequest $request) use (&$captured) {
      $captured = $request;
      return new CreateCardResponse(['card' => new Card(['id' => 'card_2'])]);
    });

    $processor = $this->processorWithMockClient(['cards' => $cardsMock]);
    $processor->createCardOnFile('cust_2', 'cnon:no-address', []);

    $this->assertNull($captured->getCard()->getBillingAddress());
  }

}
