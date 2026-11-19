<?php

require_once dirname(__DIR__) . '/Core/Payment/Square/SquareUnitTestCase.php';

use Square\Core\Pagination\Page;
use Square\Core\Pagination\Pager;
use Square\Exceptions\SquareApiException;
use Square\Invoices\InvoicesClient;
use Square\Invoices\Requests\GetInvoicesRequest;
use Square\Invoices\Requests\SearchInvoicesRequest;
use Square\Orders\OrdersClient;
use Square\Orders\Requests\BatchGetOrdersRequest;
use Square\Payments\PaymentsClient;
use Square\Payments\Requests\ListPaymentsRequest;
use Square\Refunds\RefundsClient;
use Square\SquareClient;
use Square\Subscriptions\SubscriptionsClient;
use Square\Types\BatchGetOrdersResponse;
use Square\Types\Card;
use Square\Types\CardPaymentDetails;
use Square\Types\GetInvoiceResponse;
use Square\Types\GetPaymentRefundResponse;
use Square\Types\GetPaymentResponse;
use Square\Types\GetSubscriptionResponse;
use Square\Types\Invoice;
use Square\Types\InvoicePaymentRequest;
use Square\Types\Money;
use Square\Types\Order;
use Square\Types\Payment;
use Square\Types\PaymentRefund;
use Square\Types\SearchInvoicesResponse;
use Square\Types\Subscription;
use Square\Types\Tender;

/**
 * Comparing Square's records with CiviCRM's, and processing what CiviCRM missed.
 */
class CRM_Square_ReconciliationTest extends CRM_Core_Payment_Square_SquareUnitTestCase {

  /**
   * CiviCRM payments recorded, by Square payment or refund ID.
   *
   * @var array[]
   */
  public array $recorded = [];

  /**
   * Webhook queue records, by row key.
   *
   * @var array[]
   */
  public array $queue = [];

  /**
   * Events handed to the webhook handler.
   *
   * @var array[]
   */
  public array $received = [];

  /**
   * Square events the Events API returns, as JSON.
   *
   * @var string[]
   */
  public array $events = [];

  /**
   * Mocked Square APIs, by SquareClient property.
   *
   * @var array
   */
  private array $clients = [];

  protected function setUp(): void {
    parent::setUp();
    $this->recorded = [];
    $this->queue = [];
    $this->received = [];
    $this->events = [];
    $this->clients = [];
  }

  public function testUnrecordedPaymentCanBeProcessedAsItsWebhook(): void {
    $this->paymentsReturn($this->payment('PAY-1'));

    $rows = $this->reconciliation()->findBySquareId(' PAY-1 ');

    $this->assertCount(1, $rows);
    $row = $rows[0];
    $this->assertSame('payment:PAY-1', $row['key']);
    $this->assertSame(25.0, $row['amount']);
    $this->assertSame('USD', $row['currency']);
    $this->assertSame('VISA •••• 1111', $row['detail']);
    $this->assertSame('not_recorded', $row['civicrm']['state']);
    $this->assertTrue($row['processable']);
    $this->assertSame('payment.updated', $row['payload']['type']);
    $this->assertSame('PAY-1', $row['payload']['data']['object']['payment']['id']);
    $this->assertSame(2500, $row['payload']['data']['object']['payment']['amount_money']['amount'], 'In the webhook\'s shape.');
  }

  public function testRecordedPaymentIsNotProcessedAgain(): void {
    $this->paymentsReturn($this->payment('PAY-1'));
    $this->recorded['PAY-1'] = ['contribution_id' => 77, 'contact_id' => 0, 'total_amount' => 25.0, 'currency' => 'USD'];

    $row = $this->reconciliation()->findBySquareId('PAY-1')[0];

    $this->assertSame('recorded', $row['civicrm']['state']);
    $this->assertSame('Contribution 77', $row['civicrm']['label']);
    $this->assertFalse($row['processable']);
  }

  public function testRecordedAmountThatDiffersNeedsReconcilingByHand(): void {
    $this->paymentsReturn($this->payment('PAY-1'));
    $this->recorded['PAY-1'] = ['contribution_id' => 77, 'contact_id' => 0, 'total_amount' => 20.0, 'currency' => 'USD'];

    $row = $this->reconciliation()->findBySquareId('PAY-1')[0];

    $this->assertSame('mismatch', $row['civicrm']['state']);
    $this->assertSame('Contribution 77 records 20.00 USD', $row['civicrm']['label']);
    $this->assertFalse($row['processable']);
  }

  public function testPaymentAtAnotherLocationIsLeftAlone(): void {
    $this->paymentsReturn($this->payment('PAY-1', ['locationId' => 'OTHER-LOCATION']));

    $row = $this->reconciliation()->findBySquareId('PAY-1')[0];

    $this->assertFalse($row['processable']);
    $this->assertSame('Another Square location', $row['civicrm']['label']);
  }

  public function testAnIdThatIsNoPaymentIsLookedUpAsARefund(): void {
    $this->clients['payments'] = $this->createMock(PaymentsClient::class);
    $this->clients['payments']->method('get')->willThrowException($this->notFound());
    $this->clients['refunds'] = $this->createMock(RefundsClient::class);
    $this->clients['refunds']->method('get')->willReturn(new GetPaymentRefundResponse([
      'refund' => new PaymentRefund([
        'id' => 'REF-1',
        'status' => 'COMPLETED',
        'paymentId' => 'PAY-1',
        'locationId' => 'location-id',
        'amountMoney' => new Money(['amount' => 1000, 'currency' => 'USD']),
      ]),
    ]));

    $row = $this->reconciliation()->findBySquareId('REF-1')[0];

    $this->assertSame('refund', $row['type']);
    $this->assertSame(-10.0, $row['amount']);
    $this->assertTrue($row['processable']);
    $this->assertSame('refund.updated', $row['payload']['type']);
    $this->assertSame('REF-1', $row['payload']['data']['object']['refund']['id']);
  }

  public function testAnIdSquareDoesNotHaveIsReported(): void {
    $this->clients['payments'] = $this->createMock(PaymentsClient::class);
    $this->clients['payments']->method('get')->willThrowException($this->notFound());
    $this->clients['refunds'] = $this->createMock(RefundsClient::class);
    $this->clients['refunds']->method('get')->willThrowException($this->notFound());

    $this->expectException(CRM_Core_Exception::class);
    $this->expectExceptionMessage('Square has no payment or refund NOPE');
    $this->reconciliation()->findBySquareId('NOPE');
  }

  public function testSubscriptionOfARecurringContributionListsItsInstallmentsNewestFirst(): void {
    $this->subscriptionReturns(['INV-3', 'INV-2', 'INV-1']);
    $searches = [];
    $this->clients['invoices'] = $this->createMock(InvoicesClient::class);
    $this->clients['invoices']->method('search')->willReturnCallback(function (SearchInvoicesRequest $request) use (&$searches) {
      $searches[] = $request;
      return new SearchInvoicesResponse([
        'invoices' => [
          $this->invoice('INV-1', 'PAID', 'ORD-1'),
          $this->invoice('INV-OTHER', 'PAID', 'ORD-X', 'SUB-OTHER'),
          $this->invoice('INV-2', 'UNPAID', NULL),
        ],
      ]);
    });
    // Not found by the search: fetched on its own.
    $this->clients['invoices']->expects($this->once())->method('get')
      ->with($this->callback(fn (GetInvoicesRequest $request) => $request->getInvoiceId() === 'INV-3'))
      ->willReturn(new GetInvoiceResponse(['invoice' => $this->invoice('INV-3', 'SCHEDULED', NULL)]));
    $batches = [];
    $this->clients['orders'] = $this->createMock(OrdersClient::class);
    $this->clients['orders']->method('batchGet')->willReturnCallback(function (BatchGetOrdersRequest $request) use (&$batches) {
      $batches[] = $request->getOrderIds();
      return new BatchGetOrdersResponse([
        'orders' => [new Order([
          'id' => 'ORD-1',
          'locationId' => 'location-id',
          'tenders' => [
            new Tender([
              'type' => 'CARD',
              'paymentId' => 'PAY-1',
              'amountMoney' => new Money(['amount' => 1900, 'currency' => 'USD']),
            ]),
          ],
        ]),
        ],
      ]);
    });
    $this->recorded['PAY-0'] = ['contribution_id' => 1, 'contact_id' => 0, 'total_amount' => 19.0, 'currency' => 'USD'];

    $rows = $this->reconciliation()->findBySubscription('55');

    $this->assertSame(['subscription:SUB-1', 'installment:INV-3', 'installment:INV-2', 'installment:INV-1'], array_column($rows, 'key'));
    $this->assertSame(['CUST-1'], $searches[0]->getQuery()->getFilter()->getCustomerIds());
    $this->assertSame([['ORD-1']], $batches, 'Every order in one request.');

    $this->assertTrue($rows[0]['processable'], 'A subscription\'s status can always be synced.');
    $this->assertSame('subscription.updated', $rows[0]['payload']['type']);

    $this->assertFalse($rows[1]['processable']);
    $this->assertSame('Nothing to record', $rows[1]['civicrm']['label']);

    $paid = $rows[3];
    $this->assertSame(['PAY-1'], $paid['payment_ids']);
    $this->assertSame(19.0, $paid['amount']);
    $this->assertSame('not_recorded', $paid['civicrm']['state']);
    $this->assertTrue($paid['processable']);
    $this->assertSame('invoice.payment_made', $paid['payload']['type']);
    $this->assertSame('SUB-1', $paid['payload']['data']['object']['invoice']['subscription_id']);
  }

  public function testRecurringContributionWithNoSubscriptionIsReported(): void {
    $this->expectException(CRM_Core_Exception::class);
    $this->expectExceptionMessage('Recurring contribution 99 is not linked to a Square subscription');
    $this->reconciliation()->findBySubscription('99');
  }

  public function testDateRangeListsThePaymentsAtTheLocationUpToTheLimit(): void {
    $requests = [];
    $payments = [];
    for ($i = 1; $i <= CRM_Square_Reconciliation::MAX_ROWS + 5; $i++) {
      $payments[] = $this->payment("PAY-{$i}");
    }
    $this->clients['payments'] = $this->createMock(PaymentsClient::class);
    $this->clients['payments']->method('list')->willReturnCallback(function (ListPaymentsRequest $request) use (&$requests, $payments) {
      $requests[] = $request;
      return $this->pager($payments);
    });
    $reconciliation = $this->reconciliation();

    $rows = $reconciliation->findInRange('payments', '2026-11-01', '2026-11-03');

    $this->assertCount(CRM_Square_Reconciliation::MAX_ROWS, $rows);
    $this->assertTrue($reconciliation->isTruncated());
    $this->assertSame('location-id', $requests[0]->getLocationId());
    $this->assertSame(gmdate('Y-m-d\TH:i:s\Z', strtotime('2026-11-01 00:00:00')), $requests[0]->getBeginTime());
    $this->assertSame(gmdate('Y-m-d\TH:i:s\Z', strtotime('2026-11-04 00:00:00')), $requests[0]->getEndTime(), 'The whole of the last day.');
    $this->assertSame('ASC', $requests[0]->getSortOrder());
  }

  public function testEventsCiviCrmReceivedAreNotProcessedAgain(): void {
    $this->events = [
      json_encode($this->event('EVT-1', 'PAY-1')),
      json_encode($this->event('EVT-2', 'PAY-2')),
    ];
    $this->queue['event:EVT-1'] = ['id' => 5, 'status' => 'success', 'event_id' => 'EVT-1', 'manual' => FALSE];
    $this->recorded['PAY-1'] = ['contribution_id' => 8, 'contact_id' => 0, 'total_amount' => 25.0, 'currency' => 'USD'];

    $rows = $this->reconciliation()->findEvents('2026-11-10', '2026-11-11');

    $this->assertSame(['event:EVT-1', 'event:EVT-2'], array_column($rows, 'key'));
    $this->assertFalse($rows[0]['processable']);
    $this->assertSame('recorded', $rows[0]['civicrm']['state']);
    $this->assertTrue($rows[1]['processable']);
    $this->assertSame('not_recorded', $rows[1]['civicrm']['state']);
    $this->assertSame('EVT-2', $rows[1]['payload']['event_id'], 'An event is processed as itself.');
  }

  public function testProcessingSendsEachRowAsANewEventAndEventsAsThemselves(): void {
    $this->paymentsReturn($this->payment('PAY-1'));
    $reconciliation = $this->reconciliation();
    $payment = $reconciliation->findBySquareId('PAY-1')[0];
    $this->events = [json_encode($this->event('EVT-2', 'PAY-2'))];
    $event = $reconciliation->findEvents('2026-11-10', '2026-11-11')[0];
    $notProcessable = ['processable' => FALSE, 'note' => 'Already recorded.'] + $payment;
    $notProcessable['key'] = 'payment:PAY-9';

    $outcomes = $reconciliation->process([$payment, $event, $notProcessable]);

    $this->assertCount(2, $this->received);
    $this->assertStringStartsWith(CRM_Square_Reconciliation::MANUAL_EVENT_PREFIX, $this->received[0]['event_id']);
    $this->assertSame('payment.updated', $this->received[0]['type']);
    $this->assertSame(['contact_id' => 3, 'source' => 'payment:PAY-1'], $this->received[0]['civicrm_reconcile']);
    $this->assertSame('EVT-2', $this->received[1]['event_id']);
    $this->assertSame('success', $outcomes['payment:PAY-1']['status']);
    $this->assertSame(['status' => 'skipped', 'message' => 'Already recorded.'], $outcomes['payment:PAY-9']);
  }

  public function testEachProcessingIsANewEvent(): void {
    $this->paymentsReturn($this->payment('PAY-1'));
    $reconciliation = $this->reconciliation();
    $row = $reconciliation->findBySquareId('PAY-1')[0];

    $reconciliation->process([$row]);
    $reconciliation->process([$row]);

    $this->assertNotSame($this->received[0]['event_id'], $this->received[1]['event_id']);
  }

  /**
   * A Square payment at the processor's location.
   *
   * @param string $id
   * @param array $values
   *
   * @return \Square\Types\Payment
   */
  private function payment(string $id, array $values = []): Payment {
    return new Payment($values + [
      'id' => $id,
      'status' => 'COMPLETED',
      'locationId' => 'location-id',
      'createdAt' => '2026-11-02T15:00:00Z',
      'amountMoney' => new Money(['amount' => 2500, 'currency' => 'USD']),
      'sourceType' => 'CARD',
      'cardDetails' => new CardPaymentDetails(['card' => new Card(['cardBrand' => 'VISA', 'last4' => '1111'])]),
    ]);
  }

  /**
   * A Square subscription invoice.
   *
   * @param string $id
   * @param string $status
   * @param string|null $orderId
   * @param string $subscriptionId
   *
   * @return \Square\Types\Invoice
   */
  private function invoice(string $id, string $status, ?string $orderId, string $subscriptionId = 'SUB-1'): Invoice {
    return new Invoice([
      'id' => $id,
      'status' => $status,
      'orderId' => $orderId,
      'subscriptionId' => $subscriptionId,
      'invoiceNumber' => "000{$id}",
      'paymentRequests' => [new InvoicePaymentRequest([
        'dueDate' => '2026-11-01',
        'computedAmountMoney' => new Money(['amount' => 1900, 'currency' => 'USD']),
      ]),
      ],
    ]);
  }

  /**
   * A payment.updated event, as Square's Events API returns it.
   *
   * @param string $eventId
   * @param string $paymentId
   *
   * @return array
   */
  private function event(string $eventId, string $paymentId): array {
    return [
      'type' => 'payment.updated',
      'event_id' => $eventId,
      'created_at' => '2026-11-10T10:00:00Z',
      'data' => [
        'type' => 'payment',
        'id' => $paymentId,
        'object' => [
          'payment' => [
            'id' => $paymentId,
            'status' => 'COMPLETED',
            'amount_money' => ['amount' => 2500, 'currency' => 'USD'],
          ],
        ],
      ],
    ];
  }

  /**
   * Mock the Payments API's get.
   *
   * @param \Square\Types\Payment $payment
   */
  private function paymentsReturn(Payment $payment): void {
    $this->clients['payments'] = $this->createMock(PaymentsClient::class);
    $this->clients['payments']->method('get')->willReturn(new GetPaymentResponse(['payment' => $payment]));
  }

  /**
   * Mock the Subscriptions API's get: subscription SUB-1 of customer CUST-1.
   *
   * @param string[] $invoiceIds
   */
  private function subscriptionReturns(array $invoiceIds): void {
    $this->clients['subscriptions'] = $this->createMock(SubscriptionsClient::class);
    $this->clients['subscriptions']->method('get')->willReturn(new GetSubscriptionResponse([
      'subscription' => new Subscription([
        'id' => 'SUB-1',
        'status' => 'ACTIVE',
        'customerId' => 'CUST-1',
        'locationId' => 'location-id',
        'startDate' => '2026-09-01',
        'invoiceIds' => $invoiceIds,
      ]),
    ]));
  }

  /**
   * Square's answer to a request for something it does not have.
   *
   * @return \Square\Exceptions\SquareApiException
   */
  private function notFound(): SquareApiException {
    return new SquareApiException('API request failed', 404, json_encode([
      'errors' => [['category' => 'INVALID_REQUEST_ERROR', 'code' => 'NOT_FOUND', 'detail' => 'Not found.']],
    ]));
  }

  /**
   * A pager over items.
   *
   * @param array $items
   *
   * @return \Square\Core\Pagination\Pager
   */
  private function pager(array $items): Pager {
    return new class($items) extends Pager {

      /**
       * @var array
       */
      private array $items;

      /**
       * @param array $items
       */
      public function __construct(array $items) {
        $this->items = $items;
      }

      /**
       * @return \Generator
       */
      public function getPages(): Generator {
        yield new Page($this->items);
      }

    };
  }

  /**
   * The reconciliation of processor 42, against mocked Square and CiviCRM.
   *
   * @return \CRM_Square_Reconciliation
   */
  private function reconciliation(): CRM_Square_Reconciliation {
    $config = $this->processorConfig();
    $client = new SquareClient(token: 'test-token', options: ['baseUrl' => 'https://example.invalid']);
    foreach ($this->clients as $property => $mock) {
      $client->$property = $mock;
    }
    $gateway = new CRM_Square_Gateway($config, fn () => $client);
    $processor = new CRM_Core_Payment_Square('live', $config);

    return new class($processor, $gateway, $this) extends CRM_Square_Reconciliation {

      /**
       * @var \CRM_Square_ReconciliationTest
       */
      private $test;

      /**
       * @param \CRM_Core_Payment_Square $processor
       * @param \CRM_Square_Gateway $gateway
       * @param \CRM_Square_ReconciliationTest $test
       */
      public function __construct($processor, $gateway, $test) {
        parent::__construct($processor, $gateway);
        $this->test = $test;
      }

      /**
       * @param string[] $trxnIds
       *
       * @return array[]
       */
      protected function findRecordedPayments(array $trxnIds): array {
        return array_intersect_key($this->test->recorded, array_flip($trxnIds));
      }

      /**
       * @param array $row
       *
       * @return array|null
       */
      protected function findQueueRecord(array $row): ?array {
        return $this->test->queue[$row['key']] ?? NULL;
      }

      /**
       * @param string $subscriptionId
       *
       * @return array|null
       */
      protected function findRecurBySubscriptionId(string $subscriptionId): ?array {
        return NULL;
      }

      /**
       * @param int $recurId
       *
       * @return string|null
       */
      protected function getRecurSubscriptionId(int $recurId): ?string {
        return $recurId === 55 ? 'SUB-1' : NULL;
      }

      /**
       * @param int $start
       * @param int $end
       * @param int $limit
       *
       * @return string[]
       */
      protected function searchEvents(int $start, int $end, int $limit): array {
        return array_slice($this->test->events, 0, $limit);
      }

      /**
       * @param array $payload
       *
       * @return array
       */
      protected function receive(array $payload): array {
        $this->test->received[] = $payload;
        return [
          'status' => 'success',
          'message' => 'Processed successfully',
          'queue_id' => count($this->test->received),
        ];
      }

      /**
       * @return int|null
       */
      protected function currentContactId(): ?int {
        return 3;
      }

    };
  }

}
