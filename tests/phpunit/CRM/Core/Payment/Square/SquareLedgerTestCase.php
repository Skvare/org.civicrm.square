<?php

/**
 * @file
 * In-memory CiviCRM ledger and Square fixtures for installment tests.
 */

require_once __DIR__ . '/SquareUnitTestCase.php';
require_once dirname(__DIR__, 6) . '/CRM/Core/Payment/Square.php';
require_once dirname(__DIR__, 6) . '/CRM/Core/Payment/SquareIPN.php';
require_once dirname(__DIR__, 6) . '/CRM/Core/Payment/SquareDebugLogger.php';

use Square\Types\Money;
use Square\Types\Tender;
use Square\Types\Order;
use Square\Types\Invoice;
use Square\Types\GetOrderResponse;
use Square\Orders\OrdersClient;
use Square\Types\SearchInvoicesResponse;
use Square\Invoices\InvoicesClient;
use Square\SquareClient;

/**
 * CRM_Core_Payment_Square with its CiviCRM persistence replaced by arrays.
 *
 * Only the persistence seams are replaced; all of the reconciliation logic
 * under test is the real processor's. Each seam mimics the CiviCRM
 * behaviour the logic relies on: processor and test-mode scoping,
 * Payment.create appending its trxn_id and moving a Pending series to In
 * Progress, and the unique trxn_id/invoice_id indexes on contributions.
 */
class CRM_Core_Payment_Square_FakeLedgerProcessor extends CRM_Core_Payment_Square {

  /**
   * CiviCRM's default contribution statuses (see tests/phpunit/bootstrap.php).
   */
  private const STATUS_NAMES = [
    1 => 'Completed',
    2 => 'Pending',
    3 => 'Cancelled',
    4 => 'Failed',
    5 => 'In Progress',
    7 => 'Refunded',
  ];

  /**
   * Recurring contributions by ID, for every processor.
   *
   * @var array
   */
  public array $recurs = [];

  /**
   * Contributions by ID. 'status' holds the status name.
   *
   * @var array
   */
  public array $contributions = [];

  /**
   * Recorded payments (civicrm_financial_trxn rows) by ID.
   *
   * @var array
   */
  public array $payments = [];

  /**
   * Values passed to each repeatRecurContribution() call.
   *
   * @var array
   */
  public array $repeatCalls = [];

  /**
   * Values passed to each createContribution() call.
   *
   * @var array
   */
  public array $createCalls = [];

  /**
   * Square card ID getRecurCardId() returns.
   *
   * @var string|null
   */
  public ?string $recurCardId = 'ccof:card-on-file';

  /**
   * Next ID to assign.
   *
   * @var int
   */
  private int $nextId = 900;

  /**
   * Client whose sub-clients the test has mocked.
   *
   * @var \Square\SquareClient
   */
  private SquareClient $mockClient;

  /**
   * @param array $paymentProcessor
   * @param \Square\SquareClient $mockClient
   */
  public function __construct(array $paymentProcessor, SquareClient $mockClient) {
    parent::__construct('live', $paymentProcessor);
    $this->mockClient = $mockClient;
  }

  /**
   * @return \Square\SquareClient
   */
  protected function buildSquareClient(): SquareClient {
    return $this->mockClient;
  }

  /**
   * @param string $key
   *
   * @return object
   */
  protected function acquireSquareLock(string $key) {
    return new class() {

      /**
       * Nothing to release.
       */
      public function release(): void {
      }

    };
  }

  /**
   * @param string $subscriptionId
   *
   * @return array|null
   */
  protected function findRecurBySubscriptionId(string $subscriptionId): ?array {
    foreach ($this->recurs as $recur) {
      if ($recur['processor_id'] === $subscriptionId
        && $recur['payment_processor_id'] === $this->processorId()
        && (bool) $recur['is_test'] === $this->isTestMode()) {
        return $recur;
      }
    }
    return NULL;
  }

  /**
   * @param int $recurId
   *
   * @return array
   */
  protected function getRecurContributions(int $recurId): array {
    $rows = array_filter($this->contributions, fn (array $row) => $row['contribution_recur_id'] === $recurId && empty($row['is_template']));
    ksort($rows);
    return array_values(array_map([$this, 'publicRow'], $rows));
  }

  /**
   * @param string $paymentId
   * @param string|null $referenceId
   *
   * @return array|null
   */
  protected function findContributionForSquarePayment(string $paymentId, ?string $referenceId): ?array {
    foreach ($this->contributions as $row) {
      if (empty($row['contribution_recur_id'])
        && ($row['trxn_id'] === $paymentId || ($referenceId && $row['invoice_id'] === $referenceId))) {
        return $this->publicRow($row);
      }
    }
    return NULL;
  }

  /**
   * @param string $trxnId
   *
   * @return array|null
   */
  protected function findRecordedPayment(string $trxnId): ?array {
    foreach ($this->payments as $id => $payment) {
      if ($payment['trxn_id'] === $trxnId && $payment['payment_processor_id'] === $this->processorId()) {
        return [
          'id' => $id,
          'contribution_id' => $payment['contribution_id'],
          'total_amount' => $payment['total_amount'],
        ];
      }
    }
    return NULL;
  }

  /**
   * @param int $contributionId
   * @param array $values
   * @param bool $sendReceipt
   */
  protected function recordContributionPayment(int $contributionId, array $values, bool $sendReceipt): void {
    $contribution = &$this->contributions[$contributionId];
    if ($contribution['status'] === 'Completed') {
      // CiviCRM would silently record an overpayment.
      throw new LogicException("A second payment was recorded on Completed contribution {$contributionId}.");
    }
    $this->payments[++$this->nextId] = $values + [
      'contribution_id' => $contributionId,
      'payment_processor_id' => $this->processorId(),
      'send_receipt' => $sendReceipt,
    ];

    // Payment.create appends its trxn_id to the contribution's.
    $trxnIds = array_filter(explode(',', (string) $contribution['trxn_id']));
    if (!in_array($values['trxn_id'], $trxnIds, TRUE)) {
      $trxnIds[] = $values['trxn_id'];
    }
    $this->assertUnique($contributionId, 'trxn_id', implode(',', $trxnIds));
    $contribution['trxn_id'] = implode(',', $trxnIds);
    if (isset($values['fee_amount'])) {
      $contribution['fee_amount'] = $values['fee_amount'];
    }

    if (round($values['total_amount'], 2) >= round($contribution['total_amount'], 2)) {
      $contribution['status'] = 'Completed';
      // CRM_Contribute_BAO_ContributionRecur::updateOnNewPayment().
      $recurId = $contribution['contribution_recur_id'];
      if ($recurId && $this->recurs[$recurId]['contribution_status_id'] === 2) {
        $this->recurs[$recurId]['contribution_status_id'] = 5;
      }
    }
  }

  /**
   * @param array $recur
   * @param array $values
   *
   * @return array
   */
  protected function repeatRecurContribution(array $recur, array $values): array {
    $this->repeatCalls[] = $values;
    $recurId = (int) $recur['id'];
    // The template is the series' latest contribution, whatever its status.
    $series = array_filter($this->contributions, fn (array $row) => $row['contribution_recur_id'] === $recurId);
    ksort($series);
    $template = end($series) ?: [];

    $id = ++$this->nextId;
    $this->assertUnique($id, 'trxn_id', $values['trxn_id'] ?? NULL);
    $this->assertUnique($id, 'invoice_id', $values['invoice_id'] ?? NULL);
    $this->contributions[$id] = [
      'id' => $id,
      'contribution_recur_id' => $recurId,
      'status' => $values['contribution_status'],
      'total_amount' => (float) $recur['amount'],
      'currency' => $recur['currency'],
      'trxn_id' => $values['trxn_id'] ?? NULL,
      'invoice_id' => $values['invoice_id'] ?? NULL,
      'financial_type_id' => $template['financial_type_id'] ?? NULL,
    ];
    return $this->publicRow($this->contributions[$id]);
  }

  /**
   * @param int $contributionId
   *
   * @return float
   */
  protected function getContributionFeeAmount(int $contributionId): float {
    return (float) ($this->contributions[$contributionId]['fee_amount'] ?? 0);
  }

  /**
   * @param int $contributionId
   * @param array $values
   */
  protected function updateContribution(int $contributionId, array $values): void {
    if (isset($values['contribution_status_id'])) {
      $values['status'] = self::STATUS_NAMES[$values['contribution_status_id']];
      unset($values['contribution_status_id']);
    }
    foreach (['trxn_id', 'invoice_id'] as $field) {
      if (array_key_exists($field, $values)) {
        $this->assertUnique($contributionId, $field, $values[$field]);
      }
    }
    $this->contributions[$contributionId] = $values + $this->contributions[$contributionId];
  }

  /**
   * @param array $values
   *
   * @return int
   */
  protected function createContribution(array $values): int {
    $this->createCalls[] = $values;
    $id = ++$this->nextId;
    $this->contributions[$id] = [
      'id' => $id,
      'contribution_recur_id' => NULL,
      'status' => self::STATUS_NAMES[$values['contribution_status_id']],
      'total_amount' => (float) $values['total_amount'],
      'currency' => $values['currency'],
      'trxn_id' => $values['trxn_id'] ?? NULL,
      'invoice_id' => NULL,
    ];
    return $id;
  }

  /**
   * @param array $payment
   *
   * @return int|null
   */
  protected function findContactIdForPayment(array $payment) {
    return 7;
  }

  /**
   * @param int $recurId
   *
   * @return string|null
   */
  protected function getRecurCardId(int $recurId): ?string {
    return $this->recurCardId;
  }

  /**
   * @param int $recurId
   * @param string $subscriptionId
   */
  protected function saveRecurSubscription(int $recurId, string $subscriptionId): void {
    $this->recurs[$recurId]['processor_id'] = $subscriptionId;
    $this->recurs[$recurId]['contribution_status_id'] = 2;
  }

  /**
   * @param array $params
   *
   * @return string
   */
  public function ensureSquareCustomer(array $params) {
    return 'CUSTOMER-1';
  }

  /**
   * @param string $customerID
   *
   * @return string|null
   */
  protected function findSquareCustomerById($customerID) {
    return $customerID;
  }

  /**
   * @param string $customerID
   * @param array $params
   */
  protected function updateSquareCustomerDetails($customerID, $params) {
  }

  /**
   * @param array $params
   *
   * @return string
   */
  protected function getPlanVariationIdForParams(array $params): string {
    return 'PLAN-VARIATION-1';
  }

  /**
   * Mimic the unique trxn_id/invoice_id indexes on civicrm_contribution.
   *
   * @param int $contributionId
   * @param string $field
   * @param string|null $value
   */
  private function assertUnique(int $contributionId, string $field, ?string $value): void {
    if ($value === NULL || $value === '') {
      return;
    }
    foreach ($this->contributions as $id => $row) {
      if ($id !== $contributionId && ($row[$field] ?? NULL) === $value) {
        throw new RuntimeException("Duplicate {$field} {$value} (contribution {$id}).");
      }
    }
  }

  /**
   * The row shape getRecurContributions() and friends return.
   *
   * @param array $row
   *
   * @return array
   */
  private function publicRow(array $row): array {
    $fields = ['id', 'status', 'total_amount', 'currency', 'trxn_id', 'invoice_id', 'receipt_date'];
    return array_intersect_key($row, array_flip($fields));
  }

}

/**
 * Shared fixtures for recurring-installment reconciliation tests.
 *
 * Fixtures are modelled on the Square sandbox webhooks recorded in
 * civicrm_paymentprocessor_webhook for a DAILY 19.00 USD subscription:
 * invoice.created (still DRAFT) followed by payment.updated for the payment
 * that paid it, linked only through the invoice's order_id.
 */
abstract class CRM_Core_Payment_Square_SquareLedgerTestCase extends CRM_Core_Payment_Square_SquareUnitTestCase {

  protected const PROCESSOR_ID = 42;

  protected const SUBSCRIPTION_ID = '4e6e5d41-a071-4e47-be68-087138ea360a';

  protected const CUSTOMER_ID = 'PAGRJWQKJH1E11PCR7KG7SKTY8';

  protected const LOCATION_ID = 'L6Z22RREK6NGN';

  protected const RECUR_ID = 45;

  protected const SIGNUP_CONTRIBUTION_ID = 123;

  /**
   * The first installment's Square invoice, order and payment.
   */
  protected const FIRST = [
    'invoice_id' => 'inv:0-ChAsOc2tUbZgeioE6TokE6ODEIgK',
    'order_id' => 'thVIfFwfL8yeD6fM94dvotSh2c4F',
    'payment_id' => 'VM5iP66yfHWI3UEIJWmbEAEzrrBZY',
  ];

  /**
   * The second installment's Square invoice, order and payment.
   */
  protected const SECOND = [
    'invoice_id' => 'inv:0-ChAIcc-Uqf-MREOs4fwUEwthEIgK',
    'order_id' => 'X4awkGmhfaQe3C7Z4g4o0esoje4F',
    'payment_id' => 'n1xYKpEvBD0QyjdHt4l7WW7sgBgZY',
  ];

  /**
   * Square invoices returned by the mocked invoices->search.
   *
   * @var \Square\Types\Invoice[]
   */
  protected array $squareInvoices = [];

  /**
   * Square orders returned by the mocked orders->get, by order ID.
   *
   * @var \Square\Types\Order[]
   */
  protected array $squareOrders = [];

  /**
   * Number of invoices->search calls made.
   *
   * @var int
   */
  protected int $invoiceSearches = 0;

  /**
   * @var \CRM_Core_Payment_Square_FakeLedgerProcessor
   */
  protected CRM_Core_Payment_Square_FakeLedgerProcessor $processor;

  /**
   * Reset the log and build a processor seeded like a fresh checkout.
   */
  protected function setUp(): void {
    parent::setUp();
    // Only the stand-in Civi (tests/phpunit/bootstrap.php) records log calls.
    if (property_exists(Civi::class, 'logged')) {
      Civi::$logged = [];
    }
    $this->processor = $this->ledgerProcessor();
    $this->seedCheckout($this->processor);
  }

  /**
   * A fake-ledger processor whose Square invoices/orders clients are mocked.
   *
   * @param array $configOverrides
   *
   * @return \CRM_Core_Payment_Square_FakeLedgerProcessor
   */
  protected function ledgerProcessor(array $configOverrides = []): CRM_Core_Payment_Square_FakeLedgerProcessor {
    $invoices = $this->createMock(InvoicesClient::class);
    $invoices->method('search')->willReturnCallback(function () {
      $this->invoiceSearches++;
      return new SearchInvoicesResponse(['invoices' => array_values($this->squareInvoices)]);
    });
    $orders = $this->createMock(OrdersClient::class);
    $orders->method('get')->willReturnCallback(fn ($request) => new GetOrderResponse([
      'order' => $this->squareOrders[$request->getOrderId()] ?? NULL,
    ]));

    $client = new SquareClient(token: 'test-token', options: ['baseUrl' => 'https://example.invalid']);
    $client->invoices = $invoices;
    $client->orders = $orders;
    $config = $this->processorConfig($configOverrides + ['id' => self::PROCESSOR_ID]);
    return new CRM_Core_Payment_Square_FakeLedgerProcessor($config, $client);
  }

  /**
   * What CiviCRM's checkout leaves behind once doRecurPayment() returns.
   *
   * @param \CRM_Core_Payment_Square_FakeLedgerProcessor $processor
   */
  protected function seedCheckout(CRM_Core_Payment_Square_FakeLedgerProcessor $processor): void {
    $processor->recurs[self::RECUR_ID] = [
      'id' => self::RECUR_ID,
      'contact_id' => 7,
      'amount' => 19.00,
      'currency' => 'USD',
      'processor_id' => self::SUBSCRIPTION_ID,
      'payment_processor_id' => self::PROCESSOR_ID,
      'is_test' => FALSE,
      'contribution_status_id' => 2,
      'is_email_receipt' => 1,
    ];
    $processor->contributions[self::SIGNUP_CONTRIBUTION_ID] = [
      'id' => self::SIGNUP_CONTRIBUTION_ID,
      'contribution_recur_id' => self::RECUR_ID,
      'status' => 'Pending',
      'total_amount' => 19.00,
      'currency' => 'USD',
      'trxn_id' => NULL,
      // CiviCRM's own invoice reference, set at checkout.
      'invoice_id' => 'b6f1a5c6a8f04c7e9c1d2e3f4a5b6c7d',
      'financial_type_id' => 2,
    ];
  }

  /**
   * Make an installment's invoice and order visible at (mocked) Square.
   *
   * @param array $installment
   *   self::FIRST or self::SECOND.
   * @param int $amountCents
   */
  protected function squareBills(array $installment, int $amountCents = 1900): void {
    $this->squareInvoices[$installment['invoice_id']] = new Invoice([
      'id' => $installment['invoice_id'],
      'orderId' => $installment['order_id'],
      'subscriptionId' => self::SUBSCRIPTION_ID,
      'locationId' => self::LOCATION_ID,
      'status' => 'PAID',
    ]);
    $this->squareOrders[$installment['order_id']] = new Order([
      'id' => $installment['order_id'],
      'locationId' => self::LOCATION_ID,
      'tenders' => [
        new Tender([
          'type' => 'CARD',
          'paymentId' => $installment['payment_id'],
          'amountMoney' => new Money(['amount' => $amountCents, 'currency' => 'USD']),
          'createdAt' => gmdate('Y-m-d\TH:i:s\Z'),
        ]),
      ],
    ]);
  }

  /**
   * An invoice.created payload, as Square sends it: DRAFT, nothing paid.
   *
   * @param array $installment
   *
   * @return array
   */
  protected function invoiceCreatedEvent(array $installment): array {
    return $this->invoiceEvent('invoice.created', $installment, 'DRAFT', 0);
  }

  /**
   * An invoice.payment_made payload.
   *
   * @param array $installment
   * @param int $amountCents
   *
   * @return array
   */
  protected function invoicePaymentMadeEvent(array $installment, int $amountCents = 1900): array {
    return $this->invoiceEvent('invoice.payment_made', $installment, 'PAID', $amountCents);
  }

  /**
   * An invoice.payment_failed payload.
   *
   * @param array $installment
   *
   * @return array
   */
  protected function invoicePaymentFailedEvent(array $installment): array {
    return $this->invoiceEvent('invoice.payment_failed', $installment, 'UNPAID', 0);
  }

  /**
   * A payment.updated payload, shaped like the recorded sandbox webhooks.
   *
   * No subscription_id and no reference_id: only order_id ties it to the
   * invoice.
   *
   * @param array $installment
   * @param string $status
   * @param int $amountCents
   * @param int|null $feeCents
   *   NULL for the first COMPLETED update, which does not report the fee.
   *
   * @return array
   */
  protected function paymentUpdatedEvent(array $installment, string $status = 'COMPLETED', int $amountCents = 1900, ?int $feeCents = 93): array {
    $now = gmdate('Y-m-d\TH:i:s\Z');
    $event = $this->event('payment.updated', [
      'payment' => [
        'id' => $installment['payment_id'],
        'status' => $status,
        'order_id' => $installment['order_id'],
        'customer_id' => self::CUSTOMER_ID,
        'location_id' => self::LOCATION_ID,
        'amount_money' => ['amount' => $amountCents, 'currency' => 'USD'],
        'processing_fee' => [['amount_money' => ['amount' => 93, 'currency' => 'USD'], 'type' => 'INITIAL']],
        'source_type' => 'CARD',
        'card_details' => ['entry_method' => 'ON_FILE', 'status' => 'CAPTURED'],
        'created_at' => $now,
        'updated_at' => $now,
      ],
    ]);
    if ($feeCents === NULL) {
      unset($event['data']['object']['payment']['processing_fee']);
    }
    else {
      $event['data']['object']['payment']['processing_fee'][0]['amount_money']['amount'] = $feeCents;
    }
    return $event;
  }

  /**
   * Deliver a webhook payload through the IPN router.
   *
   * @param array $payload
   */
  protected function deliver(array $payload): void {
    $this->deliverTo($this->processor, $payload);
  }

  /**
   * Deliver a webhook payload through the IPN router to a given processor.
   *
   * @param \CRM_Core_Payment_Square $processor
   * @param array $payload
   */
  protected function deliverTo(CRM_Core_Payment_Square $processor, array $payload): void {
    $ipn = new CRM_Core_Payment_SquareIPN($processor);
    $ipn->setInputParameters($payload, $payload['type']);
    $ipn->processWebhookEvent($payload, $payload['type']);
  }

  /**
   * Contributions of the test series.
   *
   * @return array
   */
  protected function seriesContributions(): array {
    return array_values(array_filter($this->processor->contributions, fn (array $row) => $row['contribution_recur_id'] === self::RECUR_ID));
  }

  /**
   * Record the first installment, as Square's invoice.payment_made reports it.
   */
  protected function payFirstInstallment(): void {
    $this->squareBills(self::FIRST);
    $this->deliver($this->invoicePaymentMadeEvent(self::FIRST));
  }

  /**
   * @param string $type
   * @param array $installment
   * @param string $status
   * @param int $completedCents
   *
   * @return array
   */
  private function invoiceEvent(string $type, array $installment, string $status, int $completedCents): array {
    $now = gmdate('Y-m-d\TH:i:s\Z');
    return $this->event($type, [
      'invoice' => [
        'id' => $installment['invoice_id'],
        'order_id' => $installment['order_id'],
        'subscription_id' => self::SUBSCRIPTION_ID,
        'location_id' => self::LOCATION_ID,
        'status' => $status,
        'primary_recipient' => ['customer_id' => self::CUSTOMER_ID],
        'payment_requests' => [
          [
            'computed_amount_money' => ['amount' => 1900, 'currency' => 'USD'],
            'total_completed_amount_money' => ['amount' => $completedCents, 'currency' => 'USD'],
          ],
        ],
        'created_at' => $now,
        'updated_at' => $now,
      ],
    ]);
  }

  /**
   * @param string $type
   * @param array $object
   *
   * @return array
   */
  private function event(string $type, array $object): array {
    return [
      'type' => $type,
      'event_id' => bin2hex(random_bytes(8)),
      'data' => ['type' => array_key_first($object), 'object' => $object],
    ];
  }

}
