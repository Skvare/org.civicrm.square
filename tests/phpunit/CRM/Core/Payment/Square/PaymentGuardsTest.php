<?php

/**
 * @file
 * Checks made before Square is asked to charge a card, and what is sent with it.
 */

require_once __DIR__ . '/SquareUnitTestCase.php';
require_once dirname(__DIR__, 4) . '/stubs/Firewall.php';

use Civi\Firewall\Event\DeclinedCardEvent;
use Civi\Payment\Exception\PaymentProcessorException;
use Square\Exceptions\SquareApiException;
use Square\Exceptions\SquareException;
use Square\Locations\LocationsClient;
use Square\Payments\PaymentsClient;
use Square\Payments\Requests\CreatePaymentRequest;
use Square\Types\CreatePaymentResponse;
use Square\Types\Payment;

/**
 * What is checked and sent before Square charges a card.
 *
 * Minimum amount, location currency, statement description, card-testing
 * reports, and future subscription start dates.
 */
class CRM_Core_Payment_Square_PaymentGuardsTest extends CRM_Core_Payment_Square_SquareUnitTestCase {

  /**
   * The CreatePayment requests sent to (mocked) Square.
   *
   * @var \Square\Payments\Requests\CreatePaymentRequest[]
   */
  private array $sent = [];

  protected function setUp(): void {
    parent::setUp();
    Civi::$settings = [];
    Civi::$logged = [];
    DeclinedCardEvent::$triggered = [];
    $this->sent = [];
  }

  public function testPaymentBelowTheMinimumIsRefusedBeforeReachingSquare(): void {
    Civi::$settings['square_min_amount'] = 5;
    $processor = $this->processorWithMockClient(['payments' => $this->payments()]);

    $params = $this->params('4.99');
    try {
      $processor->doPayment($params);
      $this->fail('A payment below the minimum must be refused.');
    }
    catch (PaymentProcessorException $e) {
      $this->assertSame('The minimum amount for a card payment is 5.00 USD.', $e->getMessage());
    }
    $this->assertSame([], $this->sent);
    $this->assertSame([], DeclinedCardEvent::$triggered, 'Square declined nothing.');
  }

  public function testPaymentAtTheMinimumIsTaken(): void {
    Civi::$settings['square_min_amount'] = 5;
    $processor = $this->processorWithMockClient(['payments' => $this->payments()]);

    $params = $this->params('5.00');
    $this->assertSame('pay_1', $processor->doPayment($params)['trxn_id']);
  }

  public function testBackOfficePaymentIsNotHeldToTheMinimum(): void {
    Civi::$settings['square_min_amount'] = 5;
    $processor = $this->processorWithMockClient(['payments' => $this->payments()]);
    $processor->setBackOffice(TRUE);

    $params = $this->params('1.00');
    $this->assertSame('pay_1', $processor->doPayment($params)['trxn_id']);
  }

  public function testZeroAmountNeedsNoCardWhateverTheMinimum(): void {
    Civi::$settings['square_min_amount'] = 5;
    $processor = $this->processorWithMockClient(['payments' => $this->payments()]);

    $params = ['amount' => '0', 'invoiceID' => 'inv-free'];
    $this->assertSame('Completed', $processor->doPayment($params)['payment_status']);
    $this->assertSame([], $this->sent);
  }

  public function testPaymentInAnotherCurrencyThanTheLocationsIsRefusedBeforeReachingSquare(): void {
    $processor = $this->processorWithMockClient([
      'payments' => $this->payments(),
      'locations' => $this->locationsClient('CAD'),
    ]);

    $params = $this->params('10.00');
    try {
      $processor->doPayment($params);
      $this->fail('A payment in a currency the location does not take must be refused.');
    }
    catch (PaymentProcessorException $e) {
      $this->assertSame('Card payments in USD cannot be taken here. Please contact us.', $e->getMessage());
    }
    $this->assertSame([], $this->sent);
    $this->assertLogged('error', "Square location takes CAD, so a USD payment was refused");
  }

  public function testStaffAreToldWhichCurrencyTheLocationTakes(): void {
    $processor = $this->processorWithMockClient([
      'payments' => $this->payments(),
      'locations' => $this->locationsClient('CAD'),
    ]);
    $processor->setBackOffice(TRUE);

    $this->expectException(PaymentProcessorException::class);
    $this->expectExceptionMessage('This Square account takes payments in CAD only, so this USD payment cannot be taken with it.');
    $params = $this->params('10.00');
    $processor->doPayment($params);
  }

  public function testPaymentGoesAheadWhenTheLocationCannotBeLookedUp(): void {
    $locations = $this->createMock(LocationsClient::class);
    $locations->method('get')->willThrowException(new SquareException('Connection timed out'));
    $processor = $this->processorWithMockClient(['payments' => $this->payments(), 'locations' => $locations]);

    $params = $this->params('10.00');
    $this->assertSame('pay_1', $processor->doPayment($params)['trxn_id']);
    $this->assertLogged('warning', 'could not look up the currency');
  }

  public function testLocationIsLookedUpOncePerCachePeriod(): void {
    $locations = $this->locationsClient();
    $locations->expects($this->once())->method('get');
    $processor = $this->processorWithMockClient(['payments' => $this->payments(), 'locations' => $locations]);

    foreach (['inv-a', 'inv-b'] as $invoiceId) {
      $params = ['invoiceID' => $invoiceId] + $this->params('10.00');
      $processor->doPayment($params);
    }
    $this->assertCount(2, $this->sent);
  }

  public function testStatementDescriptionIsSentWithOneTimePayments(): void {
    Civi::$settings['square_statement_description'] = '  AACT DONATION  ';
    $processor = $this->processorWithMockClient(['payments' => $this->payments()]);

    $params = $this->params('10.00');
    $processor->doPayment($params);
    $this->assertSame('AACT DONATION', $this->sent[0]->getStatementDescriptionIdentifier());
  }

  public function testStatementDescriptionIsCutToSquaresLimit(): void {
    Civi::$settings['square_statement_description'] = 'A DESCRIPTION LONGER THAN TWENTY';
    $processor = $this->processorWithMockClient(['payments' => $this->payments()]);

    $params = $this->params('10.00');
    $processor->doPayment($params);
    $this->assertSame('A DESCRIPTION LONGER', $this->sent[0]->getStatementDescriptionIdentifier());
  }

  public function testNoStatementDescriptionIsSentByDefault(): void {
    $processor = $this->processorWithMockClient(['payments' => $this->payments()]);

    $params = $this->params('10.00');
    $processor->doPayment($params);
    $this->assertNull($this->sent[0]->getStatementDescriptionIdentifier());
  }

  public function testDeclinedCardIsReportedToTheFirewall(): void {
    $processor = $this->processorWithMockClient(['payments' => $this->decliningPayments()]);

    try {
      $params = $this->params('10.00');
      $processor->doPayment($params);
      $this->fail('A declined card must throw.');
    }
    catch (PaymentProcessorException $e) {
      $this->assertSame('Your card was declined. Please use a different card.', $e->getMessage());
    }
    $this->assertSame([['192.0.2.10', 'CRM_Core_Payment_Square::doPayment']], DeclinedCardEvent::$triggered);
  }

  public function testCardDeclinedOnABackOfficeFormIsNotReportedToTheFirewall(): void {
    $processor = $this->processorWithMockClient(['payments' => $this->decliningPayments()]);
    $processor->setBackOffice(TRUE);

    try {
      $params = $this->params('10.00');
      $processor->doPayment($params);
      $this->fail('A declined card must throw.');
    }
    catch (PaymentProcessorException $e) {
      // Expected.
    }
    $this->assertSame([], DeclinedCardEvent::$triggered);
  }

  /**
   * @dataProvider startDateProvider
   */
  public function testSubscriptionStartDate(?string $receiveDate, ?string $expected): void {
    $processor = $this->processorWithMockClient([]);
    $params = $receiveDate === NULL ? [] : ['receive_date' => $receiveDate];
    $this->assertSame($expected, $this->callMethod($processor, 'getSubscriptionStartDate', [$params]));
  }

  public static function startDateProvider(): array {
    $nextWeek = date('Y-m-d', strtotime('+7 days'));
    return [
      'no receive_date' => [NULL, NULL],
      'empty' => ['', NULL],
      'now, as most forms pass' => [date('YmdHis'), NULL],
      'later today' => [date('Y-m-d') . ' 23:59:59', NULL],
      'yesterday' => [date('Y-m-d', strtotime('-1 day')), NULL],
      'unreadable' => ['not a date', NULL],
      'next week, as CiviCRM formats it' => [date('YmdHis', strtotime($nextWeek . ' 09:00:00')), $nextWeek],
      'next week, as the back-office form posts it' => [$nextWeek . ' 09:00:00', $nextWeek],
    ];
  }

  public function testProcessorSupportsAFutureRecurStartDate(): void {
    $this->assertTrue($this->processorWithMockClient([])->supportsFutureRecurStartDate());
  }

  /**
   * Payment params for a public one-time payment.
   *
   * @param string $amount
   *
   * @return array
   */
  private function params(string $amount): array {
    return ['square_payment_token' => 'cnon:ok', 'amount' => $amount, 'currency' => 'USD', 'invoiceID' => 'inv-1'];
  }

  /**
   * A Payments API that takes every payment, recording each request.
   *
   * @return \Square\Payments\PaymentsClient
   */
  private function payments(): PaymentsClient {
    $payments = $this->createMock(PaymentsClient::class);
    $payments->method('create')->willReturnCallback(function (CreatePaymentRequest $request) {
      $this->sent[] = $request;
      return new CreatePaymentResponse([
        'payment' => new Payment(['id' => 'pay_' . count($this->sent), 'status' => 'COMPLETED']),
      ]);
    });
    return $payments;
  }

  /**
   * A Payments API that declines every card.
   *
   * @return \Square\Payments\PaymentsClient
   */
  private function decliningPayments(): PaymentsClient {
    $payments = $this->createMock(PaymentsClient::class);
    $payments->method('create')->willThrowException(new SquareApiException('API request failed', 402, json_encode([
      'errors' => [['category' => 'PAYMENT_METHOD_ERROR', 'code' => 'CARD_DECLINED', 'detail' => 'Card declined.']],
    ])));
    return $payments;
  }

  /**
   * Assert a message containing $needle was logged at $level.
   *
   * @param string $level
   * @param string $needle
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

}
