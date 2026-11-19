<?php

use Civi\Api4\Contribution;
use Civi\Api4\ContributionRecur;
use Civi\Api4\Payment;
use Civi\Api4\PaymentprocessorWebhook;
use CRM_Square_ExtensionUtil as E;
use Square\Exceptions\SquareApiException;
use Square\Invoices\Requests\GetInvoicesRequest;
use Square\Invoices\Requests\SearchInvoicesRequest;
use Square\Orders\Requests\BatchGetOrdersRequest;
use Square\Payments\Requests\GetPaymentsRequest;
use Square\Payments\Requests\ListPaymentsRequest;
use Square\Refunds\Requests\GetRefundsRequest;
use Square\Refunds\Requests\ListRefundsRequest;
use Square\SquareClient;
use Square\Subscriptions\Requests\GetSubscriptionsRequest;
use Square\Types\InvoiceFilter;
use Square\Types\InvoiceQuery;
use Square\Types\InvoiceSort;

/**
 * Compares what Square recorded with what CiviCRM recorded, for one processor.
 *
 * Looks up Square payments, refunds, a subscription's installments, or
 * recent events, and says for each whether CiviCRM has recorded it and what
 * the webhook queue holds for it. What CiviCRM missed can then be processed
 * from Square's current data: each is turned into the webhook Square would
 * have sent (or, for an event, the event itself) and handed to
 * CRM_Core_Payment_SquareIPN, so it is processed exactly as a webhook is,
 * with the same idempotency and checks, and recorded in the webhook queue
 * (with an event ID starting MANUAL_EVENT_PREFIX) as an audit trail.
 *
 * Square cannot be asked to resend a webhook: it retries a failed delivery
 * for 24 hours, then discards it.
 *
 * Each lookup returns rows (arrays): key, type (payment, refund,
 * installment, subscription or event), square_id, detail, date, amount,
 * currency, square_status, civicrm (state, label, url), queue (the latest
 * webhook queue record for it, or NULL), processable, note, and payload
 * (the event to process).
 */
class CRM_Square_Reconciliation {

  /**
   * The most rows a lookup returns.
   */
  public const MAX_ROWS = 200;

  /**
   * Start of the event ID of each webhook queue record made here.
   */
  public const MANUAL_EVENT_PREFIX = 'civicrm-reconcile-';

  /**
   * The payment processor.
   *
   * @var \CRM_Core_Payment_Square
   */
  protected CRM_Core_Payment_Square $processor;

  /**
   * Square API access for the payment processor.
   *
   * @var \CRM_Square_Gateway
   */
  protected CRM_Square_Gateway $gateway;

  /**
   * Whether the last lookup found more than MAX_ROWS.
   *
   * @var bool
   */
  protected bool $truncated = FALSE;

  /**
   * Builds the reconciliation for one payment processor.
   *
   * @param \CRM_Core_Payment_Square $processor
   * @param \CRM_Square_Gateway $gateway
   */
  public function __construct(CRM_Core_Payment_Square $processor, CRM_Square_Gateway $gateway) {
    $this->processor = $processor;
    $this->gateway = $gateway;
  }

  /**
   * Whether the last lookup found more than MAX_ROWS, and returned only those.
   *
   * @return bool
   */
  public function isTruncated(): bool {
    return $this->truncated;
  }

  /**
   * A subscription, and each of its installments.
   *
   * @param string $reference
   *   The Square subscription ID, or the ID of the recurring contribution
   *   linked to it.
   *
   * @return array[]
   *   The subscription's row, then its installments', newest first.
   *
   * @throws \CRM_Core_Exception
   */
  public function findBySubscription(string $reference): array {
    $this->truncated = FALSE;
    $reference = trim($reference);
    $subscriptionId = ctype_digit($reference) ? $this->getRecurSubscriptionId((int) $reference) : $reference;
    if ($subscriptionId === NULL || $subscriptionId === '') {
      throw new CRM_Core_Exception(E::ts('Recurring contribution %1 is not linked to a Square subscription of this payment processor.', [1 => $reference]));
    }

    $subscription = $this->fetchSubscription($subscriptionId);
    $rows = [$this->subscriptionRow($subscription)];
    $invoices = $this->fetchSubscriptionInvoices($subscription);
    $tenders = $this->fetchOrderTenders(
      array_values(array_filter(array_column($invoices, 'order_id'))),
      (string) ($subscription['location_id'] ?? $this->gateway->getLocationId())
    );
    foreach ($invoices as $invoice) {
      $rows[] = $this->installmentRow($invoice, $tenders[$invoice['order_id'] ?? ''] ?? []);
    }
    return $this->annotate($rows);
  }

  /**
   * A Square payment or refund, by its ID.
   *
   * @param string $squareId
   *
   * @return array[]
   *   Its row.
   *
   * @throws \CRM_Core_Exception
   *   If Square has neither.
   */
  public function findBySquareId(string $squareId): array {
    $this->truncated = FALSE;
    $squareId = trim($squareId);
    try {
      $payment = $this->gateway->call(fn (SquareClient $client) => $client->payments->get(new GetPaymentsRequest(['paymentId' => $squareId])))->getPayment();
      if ($payment) {
        return $this->annotate([$this->paymentRow($this->toArray($payment))]);
      }
    }
    catch (CRM_Core_Exception $e) {
      if (!$this->isNotFound($e)) {
        throw $e;
      }
    }
    try {
      $refund = $this->gateway->call(fn (SquareClient $client) => $client->refunds->get(new GetRefundsRequest(['refundId' => $squareId])))->getRefund();
      if ($refund) {
        return $this->annotate([$this->refundRow($this->toArray($refund))]);
      }
    }
    catch (CRM_Core_Exception $e) {
      if (!$this->isNotFound($e)) {
        throw $e;
      }
    }
    throw new CRM_Core_Exception(E::ts('Square has no payment or refund %1 in this payment processor\'s account.', [1 => $squareId]));
  }

  /**
   * The payments or refunds Square took at the processor's location in a date range.
   *
   * @param string $type
   *   Either 'payments' or 'refunds'.
   * @param string $from
   *   First day, Y-m-d, in the site's time zone.
   * @param string $to
   *   Last day, Y-m-d, in the site's time zone.
   *
   * @return array[]
   *   Their rows, oldest first.
   *
   * @throws \CRM_Core_Exception
   */
  public function findInRange(string $type, string $from, string $to): array {
    [$start, $end] = $this->dayRange($from, $to);
    $values = [
      'beginTime' => gmdate('Y-m-d\TH:i:s\Z', $start),
      'endTime' => gmdate('Y-m-d\TH:i:s\Z', $end),
      'sortOrder' => 'ASC',
      'locationId' => $this->gateway->getLocationId(),
      'limit' => 100,
    ];
    // The pager calls Square as it is iterated, so iterate inside call(),
    // and stop once there is one more than will be shown.
    $items = $this->gateway->call(function (SquareClient $client) use ($type, $values) {
      $pager = $type === 'refunds'
        ? $client->refunds->list(new ListRefundsRequest($values))
        : $client->payments->list(new ListPaymentsRequest($values));
      $items = [];
      foreach ($pager as $item) {
        $items[] = $this->toArray($item);
        if (count($items) > self::MAX_ROWS) {
          break;
        }
      }
      return $items;
    });
    $items = $this->limit($items);
    $rows = array_map(fn (array $item) => $type === 'refunds' ? $this->refundRow($item) : $this->paymentRow($item), $items);
    return $this->annotate($rows);
  }

  /**
   * The events Square created in a date range, of the types CiviCRM handles.
   *
   * Square only finds events of the last 28 days, from after event search
   * was enabled (see CRM_Square_EventRecovery).
   *
   * @param string $from
   *   First day, Y-m-d, in the site's time zone.
   * @param string $to
   *   Last day, Y-m-d, in the site's time zone.
   *
   * @return array[]
   *   Their rows, oldest first.
   *
   * @throws \CRM_Core_Exception
   */
  public function findEvents(string $from, string $to): array {
    [$start, $end] = $this->dayRange($from, $to);
    $events = $this->limit($this->searchEvents($start, $end, self::MAX_ROWS + 1));
    $rows = [];
    foreach ($events as $json) {
      $event = json_decode($json, TRUE);
      if (is_array($event)) {
        $rows[] = $this->eventRow($event);
      }
    }
    return $this->annotate($rows);
  }

  /**
   * Process rows, as their webhooks would have been.
   *
   * @param array[] $rows
   *   Rows from one of the lookups; those not processable are skipped.
   *
   * @return array
   *   By row key: status (success, new: left for the scheduled job to
   *   retry, error, duplicate: already received, or skipped) and message.
   */
  public function process(array $rows): array {
    $outcomes = [];
    foreach ($rows as $row) {
      if (empty($row['processable']) || empty($row['payload'])) {
        $outcomes[$row['key']] = ['status' => 'skipped', 'message' => (string) ($row['note'] ?? '')];
        continue;
      }
      $payload = $row['payload'];
      if ($row['type'] !== 'event') {
        // A new event: Square's own events have their own IDs.
        $payload['event_id'] = self::MANUAL_EVENT_PREFIX . bin2hex(random_bytes(12));
        $payload['created_at'] = gmdate('Y-m-d\TH:i:s\Z');
        $payload['civicrm_reconcile'] = ['contact_id' => $this->currentContactId(), 'source' => $row['key']];
      }
      try {
        $outcomes[$row['key']] = $this->receive($payload);
      }
      catch (\Throwable $e) {
        $outcomes[$row['key']] = ['status' => 'error', 'message' => $e->getMessage()];
      }
    }
    return $outcomes;
  }

  /**
   * Process a webhook queue record of this processor again, now.
   *
   * @param int $queueId
   *
   * @return array
   *   status and message, as process() returns them.
   */
  public function retryQueueRecord(int $queueId): array {
    $record = $this->getQueueRecord($queueId);
    if (!$record) {
      return [
        'status' => 'skipped',
        'message' => E::ts('Webhook queue record %1 is not a failed or waiting record of this payment processor.', [1 => $queueId]),
      ];
    }
    $ipn = new CRM_Core_Payment_SquareIPN($this->processor);
    $ipn->processQueuedWebhookEvent($record);
    return $this->describeOutcome($ipn->getLastOutcome());
  }

  /**
   * Hand an event to the webhook handler, as if Square had just sent it.
   *
   * @param array $payload
   *
   * @return array
   *   status and message.
   */
  protected function receive(array $payload): array {
    $json = (string) json_encode($payload, JSON_UNESCAPED_SLASHES);
    $ipn = new CRM_Core_Payment_SquareIPN($this->processor);
    $ipn->setData($json);
    $received = $ipn->receiveEvent($payload);
    if ($received === CRM_Core_Payment_SquareIPN::RECEIVED_DUPLICATE) {
      return ['status' => 'duplicate', 'message' => E::ts('Already received.')];
    }
    if ($received !== CRM_Core_Payment_SquareIPN::RECEIVED_PROCESSED) {
      return ['status' => 'skipped', 'message' => E::ts('Not an event CiviCRM handles.')];
    }
    return $this->describeOutcome($ipn->getLastOutcome());
  }

  /**
   * The outcome of processing a queue record, as process() returns it.
   *
   * @param array|null $outcome
   *   From CRM_Core_Payment_SquareIPN::getLastOutcome().
   *
   * @return array
   */
  protected function describeOutcome(?array $outcome): array {
    return [
      'status' => (string) ($outcome['status'] ?? 'error'),
      'message' => (string) ($outcome['message'] ?? ''),
      'queue_id' => $outcome['id'] ?? NULL,
    ];
  }

  /**
   * Add each row's CiviCRM and webhook queue state, and whether it can be processed.
   *
   * @param array[] $rows
   *
   * @return array[]
   */
  protected function annotate(array $rows): array {
    $trxnIds = [];
    foreach ($rows as $row) {
      $trxnIds = array_merge($trxnIds, $row['payment_ids'] ?? []);
      if (!empty($row['refund_id'])) {
        $trxnIds[] = $row['refund_id'];
      }
    }
    $recorded = $this->findRecordedPayments(array_values(array_unique(array_filter($trxnIds))));

    foreach ($rows as &$row) {
      $row['queue'] = $this->findQueueRecord($row);
      [$row['civicrm'], $row['processable'], $row['note']] = $this->assess($row, $recorded);
    }
    unset($row);
    return $rows;
  }

  /**
   * Whether CiviCRM recorded a row, and whether processing it could change that.
   *
   * @param array $row
   * @param array $recorded
   *   From findRecordedPayments().
   *
   * @return array
   *   [civicrm: state, label and url; processable; note].
   */
  protected function assess(array $row, array $recorded): array {
    $notApplicable = ['state' => 'not_applicable', 'label' => '', 'url' => NULL];

    if ($row['type'] === 'subscription') {
      $recur = $this->findRecurBySubscriptionId((string) $row['subscription_id']);
      $civicrm = $recur ? [
        'state' => 'recorded',
        'label' => E::ts('Recurring contribution %1 (%2)', [1 => $recur['id'], 2 => $recur['status']]),
        'url' => $this->recurUrl($recur),
      ] : ['state' => 'not_recorded', 'label' => E::ts('No recurring contribution linked'), 'url' => NULL];
      return [$civicrm, TRUE, E::ts('Processing brings its status (and amount) from Square.')];
    }

    // The payment or refund the row is about, as CiviCRM recorded it.
    $squareIds = $row['refund_id'] ? [$row['refund_id']] : ($row['payment_ids'] ?? []);
    $match = NULL;
    foreach ($squareIds as $squareId) {
      $match ??= $recorded[$squareId] ?? NULL;
    }

    if ($row['type'] === 'event') {
      $civicrm = $squareIds ? $this->recordedState($row, $match) : $notApplicable;
      if ($row['queue']) {
        return [$civicrm, FALSE, E::ts('CiviCRM received this event.')];
      }
      return [$civicrm, TRUE, E::ts('CiviCRM never received this event.')];
    }

    if (!empty($row['location_id']) && $row['location_id'] !== $this->gateway->getLocationId()) {
      $civicrm = ['state' => 'not_applicable', 'label' => E::ts('Another Square location'), 'url' => NULL];
      return [$civicrm, FALSE, E::ts('Taken at another Square location, which CiviCRM ignores.')];
    }

    $civicrm = $this->recordedState($row, $match);
    if ($civicrm['state'] === 'recorded') {
      return [$civicrm, FALSE, ''];
    }
    if ($civicrm['state'] === 'mismatch') {
      return [$civicrm, FALSE, E::ts('CiviCRM recorded a different amount: reconcile it by hand.')];
    }
    $status = strtoupper((string) $row['square_status']);
    if ($row['type'] === 'installment' && $status !== 'PAID') {
      $civicrm = ['state' => 'not_applicable', 'label' => E::ts('Nothing to record'), 'url' => NULL];
      return [$civicrm, FALSE, E::ts('Square has not been paid for this invoice.')];
    }
    if ($row['type'] === 'refund' && $status !== 'COMPLETED') {
      return [$civicrm, FALSE, E::ts('Square has not completed this refund.')];
    }
    return [$civicrm, TRUE, ''];
  }

  /**
   * Whether CiviCRM recorded the payment or refund of a row.
   *
   * @param array $row
   * @param array|null $match
   *   The CiviCRM payment recorded for it, if any.
   *
   * @return array
   *   state (recorded, mismatch or not_recorded), label and url.
   */
  protected function recordedState(array $row, ?array $match): array {
    if (!$match) {
      return ['state' => 'not_recorded', 'label' => E::ts('Not recorded'), 'url' => NULL];
    }
    $url = $this->contributionUrl($match);
    $label = E::ts('Contribution %1', [1 => $match['contribution_id']]);
    $currency = $row['currency'] ?: ($match['currency'] ?? NULL);
    if ($row['amount'] !== NULL && CRM_Square_Currency::toMinorUnits(abs((float) $match['total_amount']), $currency) !== CRM_Square_Currency::toMinorUnits(abs((float) $row['amount']), $currency)) {
      $recordedAmount = CRM_Square_Currency::format(abs((float) $match['total_amount']), $currency);
      return [
        'state' => 'mismatch',
        'label' => E::ts('%1 records %2', [1 => $label, 2 => $recordedAmount]),
        'url' => $url,
      ];
    }
    return ['state' => 'recorded', 'label' => $label, 'url' => $url];
  }

  /**
   * A row for a Square payment.
   *
   * @param array $payment
   *
   * @return array
   */
  protected function paymentRow(array $payment): array {
    $card = $payment['card_details']['card'] ?? [];
    $detail = !empty($card['last_4'])
      ? trim(($card['card_brand'] ?? '') . ' •••• ' . $card['last_4'])
      : (string) ($payment['source_type'] ?? '');
    return $this->row('payment', (string) $payment['id'], [
      'detail' => $detail,
      'date' => $payment['created_at'] ?? NULL,
      'money' => $payment['amount_money'] ?? NULL,
      'square_status' => $payment['status'] ?? '',
      'payment_ids' => [(string) $payment['id']],
      'location_id' => $payment['location_id'] ?? NULL,
      'payload' => $this->payload('payment.updated', 'payment', $payment),
    ]);
  }

  /**
   * A row for a Square refund.
   *
   * @param array $refund
   *
   * @return array
   */
  protected function refundRow(array $refund): array {
    return $this->row('refund', (string) $refund['id'], [
      'detail' => E::ts('Refund of payment %1', [1 => $refund['payment_id'] ?? '']),
      'date' => $refund['created_at'] ?? NULL,
      'money' => $refund['amount_money'] ?? NULL,
      'sign' => -1,
      'square_status' => $refund['status'] ?? '',
      'refund_id' => (string) $refund['id'],
      'location_id' => $refund['location_id'] ?? NULL,
      'payload' => $this->payload('refund.updated', 'refund', $refund),
    ]);
  }

  /**
   * A row for a Square subscription.
   *
   * @param array $subscription
   *
   * @return array
   */
  protected function subscriptionRow(array $subscription): array {
    $detail = E::ts('Started %1', [1 => $subscription['start_date'] ?? '?']);
    if (!empty($subscription['canceled_date'])) {
      $detail .= '; ' . E::ts('cancelled from %1', [1 => $subscription['canceled_date']]);
    }
    return $this->row('subscription', (string) $subscription['id'], [
      'detail' => $detail,
      'date' => $subscription['created_at'] ?? NULL,
      'money' => $subscription['price_override_money'] ?? NULL,
      'square_status' => $subscription['status'] ?? '',
      'subscription_id' => (string) $subscription['id'],
      'payload' => $this->payload('subscription.updated', 'subscription', $subscription),
    ]);
  }

  /**
   * A row for a subscription installment: a Square invoice, and the payments of its order.
   *
   * @param array $invoice
   * @param array[] $tenders
   *   The tenders of the invoice's order.
   *
   * @return array
   */
  protected function installmentRow(array $invoice, array $tenders): array {
    $request = $invoice['payment_requests'][0] ?? [];
    $paymentIds = array_values(array_filter(array_map('strval', array_column($tenders, 'payment_id'))));
    $detail = E::ts('Invoice %1', [1 => $invoice['invoice_number'] ?? $invoice['id']]);
    if ($paymentIds) {
      $detail .= '; ' . E::ts('payment %1', [1 => implode(', ', $paymentIds)]);
    }
    return $this->row('installment', (string) $invoice['id'], [
      'detail' => $detail,
      'date' => $request['due_date'] ?? $invoice['created_at'] ?? NULL,
      'money' => $request['computed_amount_money'] ?? $request['total_completed_amount_money'] ?? NULL,
      'square_status' => $invoice['status'] ?? '',
      'payment_ids' => $paymentIds,
      'invoice_id' => (string) $invoice['id'],
      'subscription_id' => $invoice['subscription_id'] ?? NULL,
      'payload' => $this->payload('invoice.payment_made', 'invoice', $invoice),
    ]);
  }

  /**
   * A row for a Square event.
   *
   * @param array $event
   *
   * @return array
   */
  protected function eventRow(array $event): array {
    $type = (string) ($event['type'] ?? '');
    $object = $event['data']['object'] ?? [];
    $item = is_array($object) ? (reset($object) ?: []) : [];
    $item = is_array($item) ? $item : [];
    $objectId = (string) ($event['data']['id'] ?? $item['id'] ?? '');
    return $this->row('event', (string) ($event['event_id'] ?? ''), [
      'detail' => trim("{$type} {$objectId}"),
      'date' => $event['created_at'] ?? NULL,
      'money' => $item['amount_money'] ?? $item['payment_requests'][0]['computed_amount_money'] ?? NULL,
      'sign' => str_starts_with($type, 'refund.') ? -1 : 1,
      'square_status' => $item['status'] ?? '',
      'payment_ids' => $type === 'payment.updated' && $objectId !== '' ? [$objectId] : [],
      'refund_id' => str_starts_with($type, 'refund.') ? $objectId : NULL,
      'event_id' => (string) ($event['event_id'] ?? ''),
      'payload' => $event,
    ]);
  }

  /**
   * A row, with every field.
   *
   * @param string $type
   * @param string $squareId
   * @param array $values
   *
   * @return array
   */
  protected function row(string $type, string $squareId, array $values): array {
    $money = $values['money'] ?? NULL;
    $currency = $money['currency'] ?? NULL;
    unset($values['money']);
    $sign = $values['sign'] ?? 1;
    unset($values['sign']);
    return $values + [
      'key' => "{$type}:{$squareId}",
      'type' => $type,
      'square_id' => $squareId,
      'detail' => '',
      'date' => NULL,
      'amount' => isset($money['amount']) ? $sign * CRM_Square_Currency::fromMinorUnits($money['amount'], $currency) : NULL,
      'currency' => $currency,
      'square_status' => '',
      'payment_ids' => [],
      'refund_id' => NULL,
      'invoice_id' => NULL,
      'subscription_id' => NULL,
      'event_id' => NULL,
      'location_id' => NULL,
      'payload' => NULL,
      'civicrm' => NULL,
      'queue' => NULL,
      'processable' => FALSE,
      'note' => '',
    ];
  }

  /**
   * The webhook Square would have sent about an object.
   *
   * @param string $type
   *   The event type, e.g. payment.updated.
   * @param string $objectType
   *   The object's type: payment, refund, invoice or subscription.
   * @param array $object
   *
   * @return array
   */
  protected function payload(string $type, string $objectType, array $object): array {
    return [
      'type' => $type,
      'data' => [
        'type' => $objectType,
        'id' => (string) ($object['id'] ?? ''),
        'object' => [$objectType => $object],
      ],
    ];
  }

  /**
   * The latest webhook queue record about a row's Square object.
   *
   * A payment.updated record is matched by its payment ID, an invoice
   * event's by its invoice ID, a refund event's by the refund ID in its
   * data, and a subscription event's by its subscription ID (see
   * CRM_Core_Payment_SquareIPN::getWebhookIdentifier()).
   *
   * @param array $row
   *
   * @return array|null
   *   id, status, message, created_date, event_id, trigger, and manual (made
   *   by this reconciliation).
   */
  protected function findQueueRecord(array $row): ?array {
    $alternatives = [];
    if ($row['type'] === 'event') {
      $alternatives[] = [['event_id', '=', $row['event_id']]];
    }
    elseif ($row['type'] === 'refund') {
      $alternatives[] = [
        ['trigger', 'IN', ['refund.created', 'refund.updated']],
        ['data', 'LIKE', '%' . $this->escapeLike((string) $row['refund_id']) . '%'],
      ];
    }
    elseif ($row['type'] === 'subscription') {
      $alternatives[] = [
        ['trigger', 'IN', ['subscription.created', 'subscription.updated']],
        ['identifier', 'LIKE', '%:' . $this->escapeLike((string) $row['subscription_id'])],
      ];
    }
    else {
      if (!empty($row['invoice_id'])) {
        $alternatives[] = [['identifier', 'LIKE', '%:' . $this->escapeLike($row['invoice_id']) . ':%']];
      }
      foreach ($row['payment_ids'] as $paymentId) {
        $alternatives[] = [
          ['trigger', '=', 'payment.updated'],
          ['identifier', 'LIKE', $this->escapeLike($paymentId) . ':%'],
        ];
      }
    }
    if (!$alternatives) {
      return NULL;
    }

    $record = PaymentprocessorWebhook::get(FALSE)
      ->addSelect('id', 'status', 'message', 'created_date', 'event_id', 'trigger')
      ->addWhere('payment_processor_id', '=', $this->gateway->processorId())
      ->addClause('OR', array_map(fn (array $conditions) => ['AND', $conditions], $alternatives))
      ->addOrderBy('id', 'DESC')
      ->setLimit(1)
      ->execute()
      ->first();
    if (!$record) {
      return NULL;
    }
    $record['manual'] = str_starts_with((string) $record['event_id'], self::MANUAL_EVENT_PREFIX);
    return $record;
  }

  /**
   * A failed or waiting webhook queue record of this processor.
   *
   * @param int $queueId
   *
   * @return array|null
   */
  protected function getQueueRecord(int $queueId): ?array {
    return PaymentprocessorWebhook::get(FALSE)
      ->addWhere('id', '=', $queueId)
      ->addWhere('payment_processor_id', '=', $this->gateway->processorId())
      ->addWhere('status', 'IN', ['error', 'new'])
      ->execute()
      ->first();
  }

  /**
   * The CiviCRM payments recorded with some Square payment or refund IDs.
   *
   * @param string[] $trxnIds
   *
   * @return array[]
   *   contribution_id, contact_id, total_amount and currency, by Square ID.
   */
  protected function findRecordedPayments(array $trxnIds): array {
    if (!$trxnIds) {
      return [];
    }
    $recorded = [];
    $payments = Payment::get(FALSE)
      ->addSelect('trxn_id', 'contribution_id', 'total_amount', 'currency')
      ->addWhere('trxn_id', 'IN', $trxnIds)
      ->execute();
    foreach ($payments as $payment) {
      $recorded[$payment['trxn_id']] ??= [
        'contribution_id' => (int) $payment['contribution_id'],
        'contact_id' => 0,
        'total_amount' => (float) $payment['total_amount'],
        'currency' => $payment['currency'] ?? NULL,
      ];
    }
    // Payment's contribution_id is computed, so it cannot be joined on.
    $contributionIds = array_filter(array_column($recorded, 'contribution_id'));
    if ($contributionIds) {
      $contactIds = Contribution::get(FALSE)
        ->addSelect('id', 'contact_id')
        ->addWhere('id', 'IN', array_values(array_unique($contributionIds)))
        ->addWhere('is_test', 'IN', [TRUE, FALSE])
        ->execute()
        ->column('contact_id', 'id');
      foreach ($recorded as &$payment) {
        $payment['contact_id'] = (int) ($contactIds[$payment['contribution_id']] ?? 0);
      }
      unset($payment);
    }
    return $recorded;
  }

  /**
   * The recurring contribution of this processor linked to a Square subscription.
   *
   * @param string $subscriptionId
   *
   * @return array|null
   *   id, contact_id and status (its label).
   */
  protected function findRecurBySubscriptionId(string $subscriptionId): ?array {
    $recur = ContributionRecur::get(FALSE)
      ->addSelect('id', 'contact_id', 'contribution_status_id:label')
      ->addWhere('processor_id', '=', $subscriptionId)
      ->addWhere('payment_processor_id', '=', $this->gateway->processorId())
      ->addWhere('is_test', 'IN', [TRUE, FALSE])
      ->execute()
      ->first();
    if (!$recur) {
      return NULL;
    }
    return [
      'id' => (int) $recur['id'],
      'contact_id' => (int) $recur['contact_id'],
      'status' => (string) $recur['contribution_status_id:label'],
    ];
  }

  /**
   * The Square subscription a recurring contribution of this processor is linked to.
   *
   * @param int $recurId
   *
   * @return string|null
   */
  protected function getRecurSubscriptionId(int $recurId): ?string {
    $recur = ContributionRecur::get(FALSE)
      ->addSelect('processor_id')
      ->addWhere('id', '=', $recurId)
      ->addWhere('payment_processor_id', '=', $this->gateway->processorId())
      ->addWhere('is_test', 'IN', [TRUE, FALSE])
      ->execute()
      ->first();
    return empty($recur['processor_id']) ? NULL : (string) $recur['processor_id'];
  }

  /**
   * A Square subscription.
   *
   * @param string $subscriptionId
   *
   * @return array
   *
   * @throws \CRM_Core_Exception
   */
  protected function fetchSubscription(string $subscriptionId): array {
    try {
      $subscription = $this->gateway->call(fn (SquareClient $client) => $client->subscriptions->get(new GetSubscriptionsRequest(['subscriptionId' => $subscriptionId])))->getSubscription();
    }
    catch (CRM_Core_Exception $e) {
      if (!$this->isNotFound($e)) {
        throw $e;
      }
      $subscription = NULL;
    }
    if (!$subscription) {
      throw new CRM_Core_Exception(E::ts('Square has no subscription %1 in this payment processor\'s account.', [1 => $subscriptionId]));
    }
    return $this->toArray($subscription);
  }

  /**
   * A subscription's invoices, newest first.
   *
   * Square lists them on the subscription (invoice_ids). They are fetched by
   * searching the customer's invoices, a page of up to 200 at a time, and
   * any not found that way one by one.
   *
   * @param array $subscription
   *
   * @return array[]
   *
   * @throws \CRM_Core_Exception
   */
  protected function fetchSubscriptionInvoices(array $subscription): array {
    $invoiceIds = array_values((array) ($subscription['invoice_ids'] ?? []));
    if (count($invoiceIds) > self::MAX_ROWS - 1) {
      $this->truncated = TRUE;
      $invoiceIds = array_slice($invoiceIds, 0, self::MAX_ROWS - 1);
    }
    if (!$invoiceIds) {
      return [];
    }
    $wanted = array_flip($invoiceIds);
    $found = [];

    $customerId = $subscription['customer_id'] ?? NULL;
    $locationId = (string) ($subscription['location_id'] ?? $this->gateway->getLocationId());
    if ($customerId) {
      $cursor = NULL;
      for ($page = 0; $page < 5 && count($found) < count($wanted); $page++) {
        $request = new SearchInvoicesRequest([
          'query' => new InvoiceQuery([
            'filter' => new InvoiceFilter(['locationIds' => [$locationId], 'customerIds' => [$customerId]]),
            'sort' => new InvoiceSort(['field' => 'INVOICE_SORT_DATE', 'order' => 'DESC']),
          ]),
          'limit' => 200,
          'cursor' => $cursor,
        ]);
        $response = $this->gateway->call(fn (SquareClient $client) => $client->invoices->search($request));
        foreach ($response->getInvoices() ?? [] as $invoice) {
          if (isset($wanted[$invoice->getId()])) {
            $found[$invoice->getId()] = $this->toArray($invoice);
          }
        }
        $cursor = $response->getCursor();
        if (empty($cursor)) {
          break;
        }
      }
    }

    foreach (array_diff_key($wanted, $found) as $invoiceId => $position) {
      $invoice = $this->gateway->call(fn (SquareClient $client) => $client->invoices->get(new GetInvoicesRequest(['invoiceId' => (string) $invoiceId])))->getInvoice();
      if ($invoice) {
        $found[$invoiceId] = $this->toArray($invoice);
      }
    }

    // In the subscription's order: newest first.
    $invoices = [];
    foreach ($invoiceIds as $invoiceId) {
      if (isset($found[$invoiceId])) {
        $invoices[] = $found[$invoiceId];
      }
    }
    return $invoices;
  }

  /**
   * The tenders of some orders, which name the payments that paid them.
   *
   * @param string[] $orderIds
   * @param string $locationId
   *
   * @return array[]
   *   Each order's tenders, by order ID.
   *
   * @throws \CRM_Core_Exception
   */
  protected function fetchOrderTenders(array $orderIds, string $locationId): array {
    $tenders = [];
    foreach (array_chunk(array_unique($orderIds), 100) as $chunk) {
      $orders = $this->gateway->call(fn (SquareClient $client) => $client->orders->batchGet(new BatchGetOrdersRequest([
        'locationId' => $locationId,
        'orderIds' => $chunk,
      ])))->getOrders() ?? [];
      foreach ($orders as $order) {
        $tenders[(string) $order->getId()] = $this->toArray($order)['tenders'] ?? [];
      }
    }
    return $tenders;
  }

  /**
   * Square's events in a time range.
   *
   * @param int $start
   * @param int $end
   * @param int $limit
   *
   * @return string[]
   *   As JSON.
   *
   * @throws \CRM_Core_Exception
   */
  protected function searchEvents(int $start, int $end, int $limit): array {
    return $this->processor->eventRecovery()->search($start, $end, $limit);
  }

  /**
   * The logged-in contact, recorded with each manual queue record.
   *
   * @return int|null
   */
  protected function currentContactId(): ?int {
    $contactId = CRM_Core_Session::getLoggedInContactID();
    return $contactId ? (int) $contactId : NULL;
  }

  /**
   * Keep the first MAX_ROWS items, noting whether there were more.
   *
   * @param array $items
   *
   * @return array
   */
  protected function limit(array $items): array {
    $this->truncated = count($items) > self::MAX_ROWS;
    return array_slice($items, 0, self::MAX_ROWS);
  }

  /**
   * The start and end of a range of days in the site's time zone.
   *
   * @param string $from
   *   Y-m-d.
   * @param string $to
   *   Y-m-d.
   *
   * @return int[]
   *   [start, end] as Unix timestamps; end is the start of the day after $to.
   *
   * @throws \CRM_Core_Exception
   */
  protected function dayRange(string $from, string $to): array {
    $start = strtotime($from . ' 00:00:00');
    $end = strtotime($to . ' 00:00:00 +1 day');
    if ($start === FALSE || $end === FALSE || $start >= $end) {
      throw new CRM_Core_Exception(E::ts('Enter a valid date range.'));
    }
    return [$start, $end];
  }

  /**
   * Whether an API error is Square saying the object does not exist.
   *
   * @param \CRM_Core_Exception $e
   *
   * @return bool
   */
  protected function isNotFound(CRM_Core_Exception $e): bool {
    $previous = $e->getPrevious();
    return $previous instanceof SquareApiException && $previous->getStatusCode() === 404;
  }

  /**
   * A Square SDK object as the arrays Square's webhooks carry.
   *
   * @param object $object
   *
   * @return array
   */
  protected function toArray(object $object): array {
    return method_exists($object, 'toJson') ? (array) json_decode($object->toJson(), TRUE) : [];
  }

  /**
   * Escape a value for a LIKE pattern.
   *
   * @param string $value
   *
   * @return string
   */
  protected function escapeLike(string $value): string {
    return addcslashes($value, '\\%_');
  }

  /**
   * The URL of a contribution.
   *
   * @param array $recorded
   *   From findRecordedPayments().
   *
   * @return string|null
   */
  protected function contributionUrl(array $recorded): ?string {
    if (empty($recorded['contribution_id']) || empty($recorded['contact_id'])) {
      return NULL;
    }
    return CRM_Utils_System::url('civicrm/contact/view/contribution', "reset=1&action=view&id={$recorded['contribution_id']}&cid={$recorded['contact_id']}");
  }

  /**
   * The URL of a recurring contribution.
   *
   * @param array $recur
   *   From findRecurBySubscriptionId().
   *
   * @return string|null
   */
  protected function recurUrl(array $recur): ?string {
    return CRM_Utils_System::url('civicrm/contact/view/contributionrecur', "reset=1&id={$recur['id']}&cid={$recur['contact_id']}");
  }

}
