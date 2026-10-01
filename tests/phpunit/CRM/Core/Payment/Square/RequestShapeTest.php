<?php

require_once __DIR__ . '/SquareUnitTestCase.php';

use Civi\Payment\Exception\PaymentProcessorException;
use Square\SquareClient;
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

    // A retry could not be recognized without one, so it is never charged,
    // and CiviCRM's checkout is told the payment failed.
    try {
      $processor->doPayment($params);
      $this->fail('A payment without a checkout reference must not be taken.');
    }
    catch (PaymentProcessorException $e) {
      $this->assertStringContainsString('without a CiviCRM invoice or contribution reference', $e->getPrevious()->getMessage());
    }
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
    $captured = [];
    $processor = $this->refundingProcessor($captured, ['CAD', 0]);
    $params = ['trxn_id' => 'pay_99', 'amount' => '5.00', 'currency' => 'USD'];
    $processor->doRefund($params);

    $this->assertSame('pay_99', $captured[0]->getPaymentId());
    $this->assertSame(500, $captured[0]->getAmountMoney()->getAmount());
    // The recorded payment's own currency, whatever the caller passed.
    $this->assertSame('CAD', $captured[0]->getAmountMoney()->getCurrency());
    $this->assertNotEmpty($captured[0]->getIdempotencyKey());
    $this->assertLessThanOrEqual(45, strlen($captured[0]->getIdempotencyKey()));
  }

  public function testRefundOfAPaymentCiviCrmDoesNotKnowUsesTheGivenCurrency(): void {
    $captured = [];
    $params = ['trxn_id' => 'pay_99', 'amount' => '5.00', 'currencyID' => 'CAD'];
    $this->refundingProcessor($captured, [NULL, 0])->doRefund($params);

    $this->assertSame('CAD', $captured[0]->getAmountMoney()->getCurrency());
  }

  public function testRefundIdempotencyKeyDistinguishesRepeatedRefundsOfTheSameAmount(): void {
    $params = ['trxn_id' => 'pay_99', 'amount' => '5.00'];
    $first = [];
    $this->refundingProcessor($first, ['USD', 0])->doRefund($params);
    // The first refund's outcome was lost, so CiviCRM recorded nothing.
    $retry = [];
    $this->refundingProcessor($retry, ['USD', 0])->doRefund($params);
    // CiviCRM recorded the first refund.
    $second = [];
    $this->refundingProcessor($second, ['USD', 1])->doRefund($params);

    // The retry gets Square's original refund back, not a second refund...
    $this->assertSame($first[0]->getIdempotencyKey(), $retry[0]->getIdempotencyKey());
    // ...but a further $5 refund of the payment is a new refund.
    $this->assertNotSame($first[0]->getIdempotencyKey(), $second[0]->getIdempotencyKey());
  }

  /**
   * A processor with a mocked Square, capturing refund requests.
   *
   * @param array $captured
   *   Receives each RefundPaymentRequest sent.
   * @param array $refundContext
   *   What getRefundContext() returns: the recorded payment's currency
   *   (NULL if not recorded), and the refunds CiviCRM has recorded.
   */
  private function refundingProcessor(array &$captured, array $refundContext): CRM_Core_Payment_Square {
    $refundsMock = $this->createMock(RefundsClient::class);
    $refundsMock->method('refundPayment')->willReturnCallback(function ($request) use (&$captured) {
      $captured[] = $request;
      return new RefundPaymentResponse([
        'refund' => new PaymentRefund([
          'id' => 'refund_' . count($captured),
          'status' => 'PENDING',
          'amountMoney' => $request->getAmountMoney(),
        ]),
      ]);
    });
    $client = new SquareClient(token: 'test-token', options: ['baseUrl' => 'https://example.invalid']);
    $client->refunds = $refundsMock;
    $config = $this->processorConfig();

    return new class($config, $client, $refundContext) extends CRM_Core_Payment_Square {

      /**
       * @var \Square\SquareClient
       */
      private SquareClient $mockClient;

      /**
       * @var array
       */
      private array $refundContext;

      public function __construct(array &$paymentProcessor, SquareClient $mockClient, array $refundContext) {
        parent::__construct('live', $paymentProcessor);
        $this->mockClient = $mockClient;
        $this->refundContext = $refundContext;
      }

      protected function buildSquareClient(): SquareClient {
        return $this->mockClient;
      }

      protected function getRefundContext(string $paymentTrxnId): array {
        return $this->refundContext;
      }

    };
  }

  /**
   * @dataProvider billingFieldsProvider
   */
  public function testCreateCardOnFileRequestShapeWithBillingAddress(array $billingFields): void {
    $captured = NULL;
    $cardsMock = $this->createMock(CardsClient::class);
    $cardsMock->method('create')->willReturnCallback(function (CreateCardRequest $request) use (&$captured) {
      $captured = $request;
      return new CreateCardResponse(['card' => new Card(['id' => 'card_1', 'last4' => '1111'])]);
    });

    $processor = $this->processorWithMockClient(['cards' => $cardsMock]);
    // No contactId (4th arg omitted) -> skips PaymentToken creation, which
    // needs a real CiviCRM DB unavailable in this suite.
    $cardId = $processor->createCardOnFile('cust_1', 'cnon:card-nonce', $billingFields);

    $this->assertSame('card_1', $cardId);
    $this->assertSame('cnon:card-nonce', $captured->getSourceId());
    $this->assertSame('cust_1', $captured->getCard()->getCustomerId());
    $billingAddress = $captured->getCard()->getBillingAddress();
    $this->assertSame('123 Main St', $billingAddress->getAddressLine1());
    $this->assertSame('Crossville', $billingAddress->getLocality());
    $this->assertSame('TN', $billingAddress->getAdministrativeDistrictLevel1());
    $this->assertSame('38555', $billingAddress->getPostalCode());
    $this->assertSame('US', $billingAddress->getCountry());
  }

  public static function billingFieldsProvider(): array {
    return [
      'billing block fields, as submitted' => [
        [
          'billing_street_address-5' => '123 Main St',
          'billing_city-5' => 'Crossville',
          'billing_state_province-5' => 'TN',
          'billing_postal_code-5' => '38555',
          'billing_country-5' => 'US',
        ],
      ],
      'state and country submitted by ID' => [
        [
          'billing_street_address-5' => '123 Main St',
          'billing_city-5' => 'Crossville',
          'billing_state_province_id-5' => '1042',
          'billing_postal_code-5' => '38555',
          'billing_country_id-5' => '1228',
        ],
      ],
      'as mapped by CiviCRM checkout' => [
        [
          'street_address' => '123 Main St',
          'city' => 'Crossville',
          'state_province' => 'TN',
          'postal_code' => '38555',
          'country' => 'us',
        ],
      ],
    ];
  }

  public function testCreateCardOnFileNeverGuessesTheCountry(): void {
    $captured = NULL;
    $cardsMock = $this->createMock(CardsClient::class);
    $cardsMock->method('create')->willReturnCallback(function (CreateCardRequest $request) use (&$captured) {
      $captured = $request;
      return new CreateCardResponse(['card' => new Card(['id' => 'card_3'])]);
    });

    $processor = $this->processorWithMockClient(['cards' => $cardsMock]);
    $processor->createCardOnFile('cust_3', 'cnon:named-country', [
      'billing_postal_code-5' => 'K1A 0B1',
      'billing_country-5' => 'Canada',
    ]);

    $this->assertSame('K1A 0B1', $captured->getCard()->getBillingAddress()->getPostalCode());
    $this->assertNull($captured->getCard()->getBillingAddress()->getCountry(), 'Not an ISO code, so not sent — never defaulted to US.');
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
