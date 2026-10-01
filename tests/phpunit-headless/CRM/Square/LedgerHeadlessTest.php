<?php

use Civi\Api4\Contribution;
use Civi\Api4\ContributionRecur;
use Civi\Api4\Payment;
use Square\Orders\OrdersClient;
use Square\Subscriptions\SubscriptionsClient;
use Square\Types\GetOrderResponse;
use Square\Types\GetSubscriptionResponse;
use Square\Types\Money;
use Square\Types\Order;
use Square\Types\Subscription;
use Square\Types\Tender;

/**
 * Square webhooks recorded through CiviCRM's real ledger APIs.
 *
 * Payment.create, Contribution.repeattransaction and refunds, and the
 * recurring statuses, as CiviCRM 6.16 defines them.
 *
 * @group headless
 */
class CRM_Square_LedgerHeadlessTest extends CRM_Square_HeadlessTestCase {

  private const SUBSCRIPTION_ID = 'SUBSCRIPTION-1';

  /**
   * Square orders, by ID, as the mocked Square returns them.
   *
   * @var \Square\Types\Order[]
   */
  private array $orders = [];

  /**
   * Square subscriptions, by ID, as the mocked Square returns them.
   *
   * @var \Square\Types\Subscription[]
   */
  private array $subscriptions = [];

  public function testRecurringStatusesComeFromTheirOwnOptionGroup(): void {
    $inProgress = (int) CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_ContributionRecur', 'contribution_status_id', 'In Progress');

    $this->assertGreaterThan(0, $inProgress);
    $this->assertSame($inProgress, CRM_Square_Status::recurStatusId('In Progress'));
    $this->assertSame($inProgress, CRM_Square_Status::mapSubscriptionStatus('ACTIVE'));
    $this->assertSame(CRM_Square_Status::recurStatusId('Cancelled'), CRM_Square_Status::mapSubscriptionStatus('CANCELED'));
  }

  public function testContributionStatusesHaveNoInProgress(): void {
    // Why recurring statuses need recurStatusId(): on a 6.16 schema this
    // status exists only for recurring contributions.
    $this->expectException(CRM_Core_Exception::class);
    CRM_Square_Status::contributionStatusId('In Progress');
  }

  public function testInstallmentsRefundAndCancellationAreRecorded(): void {
    $contactId = $this->createContact('Pat');
    $recurId = $this->createRecur($contactId);
    $signupId = $this->createPendingContribution($contactId, $recurId);
    $reconciler = $this->reconciler();

    // The first invoice completes the checkout's Pending contribution.
    $this->squareBills('ORDER-1', 'PAYMENT-1');
    $reconciler->handleInvoicePaymentCreated($this->invoicePaidEvent('INVOICE-1', 'ORDER-1'));

    $this->assertSame('Completed', $this->contributionStatus($signupId));
    $this->assertSame(['PAYMENT-1'], $this->paymentTrxnIds($signupId));
    $this->assertSame(CRM_Square_Status::recurStatusId('In Progress'), $this->recur($recurId)['contribution_status_id']);

    // Square reports its processing fee in a later payment.updated: it is
    // recorded on the payment and the contribution alike.
    $reconciler->syncPaymentFromSquare([
      'id' => 'PAYMENT-1',
      'status' => 'COMPLETED',
      'processing_fee' => [['amount_money' => ['amount' => 93, 'currency' => 'USD']]],
    ]);
    $this->assertSame(0.93, (float) Contribution::get(FALSE)->addSelect('fee_amount')->addWhere('id', '=', $signupId)->execute()->single()['fee_amount']);
    $this->assertSame(0.93, (float) Payment::get(FALSE)->addSelect('fee_amount')->addWhere('trxn_id', '=', 'PAYMENT-1')->execute()->single()['fee_amount']);

    // The second creates the next contribution of the series, and replays
    // record nothing further.
    $this->squareBills('ORDER-2', 'PAYMENT-2');
    $reconciler->handleInvoicePaymentCreated($this->invoicePaidEvent('INVOICE-2', 'ORDER-2'));
    $reconciler->handleInvoicePaymentCreated($this->invoicePaidEvent('INVOICE-2', 'ORDER-2'));

    $series = $this->seriesContributions($recurId);
    $this->assertCount(2, $series);
    $second = $series[1];
    $this->assertSame('Completed', $second['contribution_status_id:name']);
    $this->assertSame(19.0, (float) $second['total_amount']);
    $this->assertSame(['PAYMENT-2'], $this->paymentTrxnIds((int) $second['id']));

    // A completed refund of the second installment, reported twice.
    $refund = [
      'id' => 'REFUND-1',
      'payment_id' => 'PAYMENT-2',
      'status' => 'COMPLETED',
      'amount_money' => ['amount' => 1900, 'currency' => 'USD'],
    ];
    $reconciler->syncRefundFromSquare($refund);
    $reconciler->syncRefundFromSquare($refund);

    $refunds = Payment::get(FALSE)
      ->addSelect('total_amount')
      ->addWhere('contribution_id', '=', $second['id'])
      ->addWhere('trxn_id', '=', 'REFUND-1')
      ->execute();
    $this->assertCount(1, $refunds);
    $this->assertSame(-19.0, (float) $refunds->first()['total_amount']);

    // What doRefund() keys a refund's idempotency on.
    $processorRow = $this->processor;
    $square = new class('live', $processorRow) extends CRM_Core_Payment_Square {

      public function refundContext(string $paymentId): array {
        return $this->getRefundContext($paymentId);
      }

    };
    $this->assertSame(['USD', 1], $square->refundContext('PAYMENT-2'));
    $this->assertSame(['USD', 0], $square->refundContext('PAYMENT-1'));
    $this->assertSame([NULL, 0], $square->refundContext('PAYMENT-UNKNOWN'));

    // Cancelled at Square: arrives as subscription.updated.
    $this->subscriptions[self::SUBSCRIPTION_ID] = new Subscription([
      'id' => self::SUBSCRIPTION_ID,
      'status' => 'CANCELED',
      'canceledDate' => '2026-10-15',
    ]);
    $reconciler->syncSubscriptionFromSquare(self::SUBSCRIPTION_ID);

    $recur = $this->recur($recurId);
    $this->assertSame(CRM_Square_Status::recurStatusId('Cancelled'), $recur['contribution_status_id']);
    $this->assertSame('2026-10-15 00:00:00', $recur['cancel_date']);
  }

  /**
   * The reconciler for the test processor, with Square mocked.
   *
   * @return \CRM_Square_Reconciler
   */
  private function reconciler(): CRM_Square_Reconciler {
    $orders = $this->createMock(OrdersClient::class);
    $orders->method('get')->willReturnCallback(fn ($request) => new GetOrderResponse([
      'order' => $this->orders[$request->getOrderId()] ?? NULL,
    ]));
    $subscriptions = $this->createMock(SubscriptionsClient::class);
    $subscriptions->method('get')->willReturnCallback(fn ($request) => new GetSubscriptionResponse([
      'subscription' => $this->subscriptions[$request->getSubscriptionId()] ?? NULL,
    ]));
    return new CRM_Square_Reconciler($this->gateway($this->squareClient([
      'orders' => $orders,
      'subscriptions' => $subscriptions,
    ])));
  }

  /**
   * Make Square show an invoice's order as paid by a payment.
   *
   * @param string $orderId
   * @param string $paymentId
   */
  private function squareBills(string $orderId, string $paymentId): void {
    $this->orders[$orderId] = new Order([
      'id' => $orderId,
      'locationId' => 'LOCATION-1',
      'tenders' => [
        new Tender([
          'type' => 'CARD',
          'paymentId' => $paymentId,
          'amountMoney' => new Money(['amount' => 1900, 'currency' => 'USD']),
          'createdAt' => gmdate('Y-m-d\TH:i:s\Z'),
        ]),
      ],
    ]);
  }

  /**
   * An invoice.payment_made payload.
   *
   * @param string $invoiceId
   * @param string $orderId
   *
   * @return array
   */
  private function invoicePaidEvent(string $invoiceId, string $orderId): array {
    return [
      'data' => [
        'object' => [
          'invoice' => [
            'id' => $invoiceId,
            'order_id' => $orderId,
            'subscription_id' => self::SUBSCRIPTION_ID,
            'status' => 'PAID',
            'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
          ],
        ],
      ],
    ];
  }

  /**
   * A Pending recurring contribution linked to the test subscription.
   *
   * @param int $contactId
   *
   * @return int
   */
  private function createRecur(int $contactId): int {
    return (int) ContributionRecur::create(FALSE)
      ->setValues([
        'contact_id' => $contactId,
        'amount' => 19.00,
        'currency' => 'USD',
        'frequency_unit' => 'month',
        'frequency_interval' => 1,
        'financial_type_id:name' => 'Donation',
        'payment_processor_id' => $this->processor['id'],
        'processor_id' => self::SUBSCRIPTION_ID,
        'contribution_status_id:name' => 'Pending',
        'is_test' => FALSE,
        // No receipt emails from a test.
        'is_email_receipt' => FALSE,
      ])
      ->execute()
      ->single()['id'];
  }

  /**
   * The Pending contribution CiviCRM's checkout creates for the first installment.
   *
   * @param int $contactId
   * @param int $recurId
   *
   * @return int
   */
  private function createPendingContribution(int $contactId, int $recurId): int {
    return (int) Contribution::create(FALSE)
      ->setValues([
        'contact_id' => $contactId,
        'contribution_recur_id' => $recurId,
        'total_amount' => 19.00,
        'currency' => 'USD',
        'financial_type_id:name' => 'Donation',
        'contribution_status_id:name' => 'Pending',
        'payment_processor_id' => $this->processor['id'],
        'is_pay_later' => FALSE,
        'is_test' => FALSE,
      ])
      ->execute()
      ->single()['id'];
  }

  /**
   * @param int $contributionId
   *
   * @return string
   */
  private function contributionStatus(int $contributionId): string {
    return Contribution::get(FALSE)
      ->addSelect('contribution_status_id:name')
      ->addWhere('id', '=', $contributionId)
      ->execute()
      ->single()['contribution_status_id:name'];
  }

  /**
   * The trxn_ids of the (positive) payments recorded on a contribution.
   *
   * @param int $contributionId
   *
   * @return string[]
   */
  private function paymentTrxnIds(int $contributionId): array {
    return Payment::get(FALSE)
      ->addSelect('trxn_id')
      ->addWhere('contribution_id', '=', $contributionId)
      ->addWhere('total_amount', '>', 0)
      ->execute()
      ->column('trxn_id');
  }

  /**
   * @param int $recurId
   *
   * @return array
   */
  private function recur(int $recurId): array {
    $recur = ContributionRecur::get(FALSE)
      ->addSelect('contribution_status_id', 'cancel_date')
      ->addWhere('id', '=', $recurId)
      ->execute()
      ->single();
    $recur['contribution_status_id'] = (int) $recur['contribution_status_id'];
    return $recur;
  }

  /**
   * @param int $recurId
   *
   * @return array
   */
  private function seriesContributions(int $recurId): array {
    return Contribution::get(FALSE)
      ->addSelect('id', 'total_amount', 'contribution_status_id:name')
      ->addWhere('contribution_recur_id', '=', $recurId)
      ->addWhere('is_template', '=', FALSE)
      ->addOrderBy('id')
      ->execute()
      ->getArrayCopy();
  }

}
