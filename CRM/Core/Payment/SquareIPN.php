<?php

use Civi\Api4\PaymentprocessorWebhook;

/**
 * Class CRM_Core_Payment_SquareIPN.
 *
 * Processes Square webhook events and syncs them into CiviCRM.
 *
 * Entry point: onReceiveWebhook() — called from CRM_Core_Payment_Square::handlePaymentNotification().
 *
 * Handles:
 *   subscription.created, subscription.updated, subscription.canceled
 *   invoice.created, invoice.payment_made, invoice.payment_failed
 *   payment.updated, refund.created, refund.updated
 *
 * Webhook lifecycle:
 *   1. onReceiveWebhook() validates the event type, deduplicates via
 *      civicrm_paymentprocessor_webhook (a table owned by the mjwshared
 *      extension, which this extension requires), records the event and
 *      processes it immediately in the same request.
 *   2. mjwshared's "Process Payment Processor Webhooks" scheduled job
 *      (Job.process_paymentprocessor_webhooks) is a backstop: it only picks
 *      up rows in status 'new', which is where a transient failure (see
 *      CRM_Core_Payment_SquareRetryableException) leaves an event, and calls
 *      CRM_Core_Payment_Square::processWebhookEvent(), which invokes
 *      processQueuedWebhookEvent() here.
 */
class CRM_Core_Payment_SquareIPN {

  /**
   * How long, in seconds, a transiently failing event keeps being retried.
   *
   * The mjwshared job retries 'new' rows on every run with no attempt
   * limit, so without this bound an event that can never be resolved would
   * be retried until the job's own cleanup deletes it months later.
   */
  public const RETRY_WINDOW_SECONDS = 72 * 3600;

  /**
   * Payment processor handling this webhook.
   *
   * @var CRM_Core_Payment_Square
   */
  protected $_paymentProcessor;

  /**
   * Webhook event ID being processed.
   *
   * @var string|null
   */
  protected $event_id = NULL;

  /**
   * Webhook event type being processed.
   *
   * @var string
   */
  protected $event_type = '';

  /**
   * Square subscription ID from the current event.
   *
   * @var string|null
   */
  protected $subscription_id = NULL;

  /**
   * Square invoice ID from the current event.
   *
   * @var string|null
   */
  protected $invoice_id = NULL;

  /**
   * Square customer ID from the current event.
   *
   * @var string|null
   */
  protected $customer_id = NULL;

  /**
   * Square payment ID from the current event.
   *
   * @var string|null
   */
  protected $payment_id = NULL;

  /**
   * The data provided by the IPN.
   *
   * @var array|Object|string
   */
  protected $data;

  /**
   * Create an IPN processor.
   *
   * @param CRM_Core_Payment_Square $processor
   */
  public function __construct($processor) {
    $this->_paymentProcessor = $processor;
  }

  /**
   * Square event types this class handles.
   *
   * @return string[]
   */
  public static function getSupportedEventTypes(): array {
    return [
      'subscription.created',
      'subscription.updated',
      'subscription.canceled',
      'invoice.created',
      'invoice.payment_made',
      'invoice.payment_failed',
      'payment.updated',
      'refund.created',
      'refund.updated',
    ];
  }

  /**
   * Main entry point — called from Square::handlePaymentNotification().
   *
   * Records the webhook in civicrm_paymentprocessor_webhook for
   * deduplication and audit trail, then processes it immediately, in the
   * same request, so donors and staff see the result without waiting for
   * cron. The scheduled job only retries events left in status 'new' by a
   * transient failure (see processQueuedWebhookEvent()).
   *
   * @param array $payload
   *   Decoded JSON webhook payload.
   *
   * @return bool TRUE on success.
   */
  public function onReceiveWebhook(array $payload): bool {
    $eventId = $payload['event_id'] ?? NULL;
    $eventType = $payload['type'] ?? 'unknown';

    $this->event_id = $eventId;
    $this->event_type = $eventType;

    CRM_Core_Payment_SquareDebugLogger::log("Square IPN: onReceiveWebhook() called. event_id={$eventId}, type={$eventType}, processor_id={$this->_paymentProcessor->getID()}");

    // Ignore event types we do not handle (return 200 so Square does not retry).
    if (!in_array($eventType, self::getSupportedEventTypes(), TRUE)) {
      CRM_Core_Payment_SquareDebugLogger::log("Square IPN: ignoring unsupported event type '{$eventType}'.");
      return TRUE;
    }

    $this->setInputParameters($payload, $eventType);
    $identifier = $this->getWebhookIdentifier();
    $processorId = $this->_paymentProcessor->getID();

    if (!$eventId) {
      Civi::log()->error('Square IPN: webhook event has no event_id.');
      return FALSE;
    }

    // Guard the check-then-insert dedup below with a lock scoped to this
    // processor+event, so two concurrent deliveries of the same event
    // (Square does redeliver) can't both pass the "no existing row" check.
    $lock = new CRM_Core_Lock("worker.square.webhook.{$processorId}.{$eventId}", 30);
    if (!$lock->acquire()) {
      CRM_Core_Payment_SquareDebugLogger::log("Square IPN: could not acquire dedup lock for event '{$eventId}', treating as in-flight duplicate.");
      return TRUE;
    }

    try {
      // Deduplicate across every queue state. A replay is never reprocessed
      // here: a row still in 'new' is retried by the scheduled job, and an
      // 'error' row needs someone to look at it first.
      $existing = PaymentprocessorWebhook::get(FALSE)
        ->addSelect('id')
        ->addWhere('payment_processor_id', '=', $processorId)
        ->addWhere('event_id', '=', (string) $eventId)
        ->execute()
        ->first();
      if ($existing) {
        CRM_Core_Payment_SquareDebugLogger::log("Square IPN: duplicate event '{$eventId}' already queued, skipping.");
        return TRUE;
      }

      // Recorded in the default status 'new', so that if this request dies
      // while processing it below, the scheduled job still retries it. A
      // cron run picking it up concurrently is harmless: recording is
      // idempotent and serialized by CRM_Core_Payment_Square's locks.
      $newWebhookEvent = PaymentprocessorWebhook::create(FALSE)
        ->addValue('payment_processor_id', $processorId)
        ->addValue('trigger', $eventType)
        ->addValue('identifier', $identifier)
        ->addValue('event_id', (string) $eventId)
        ->addValue('data', $this->getData())
        ->execute()
        ->first();
    }
    finally {
      $lock->release();
    }

    return $this->processQueuedWebhookEvent($newWebhookEvent);
  }

  /**
   * Process a single queued webhook event and update its record.
   *
   * Called immediately from onReceiveWebhook(), and later by the scheduled
   * job (via CRM_Core_Payment_Square::processWebhookEvent()) for any row
   * left in status 'new'.
   *
   * Outcomes:
   *  - 'success': processed.
   *  - 'new': a CRM_Core_Payment_SquareRetryableException was thrown (Square
   *    API unavailable, or a related record not there yet because webhooks
   *    arrived out of order) — left for the job to retry, for up to
   *    RETRY_WINDOW_SECONDS after the event was first received.
   *  - 'error': anything else, including a retryable failure that outlived
   *    the retry window. Never retried automatically.
   *
   * @param array $webhookEvent
   *
   * @return bool TRUE unless the event failed permanently.
   */
  public function processQueuedWebhookEvent(array $webhookEvent): bool {
    $payload = $webhookEvent['data'];
    if (is_string($payload)) {
      $payload = json_decode($payload, TRUE) ?? [];
    }

    $eventType = $webhookEvent['trigger'];
    $this->event_id = $webhookEvent['event_id'];
    $this->event_type = $eventType;

    CRM_Core_Payment_SquareDebugLogger::log("Square IPN: processQueuedWebhookEvent() called. webhook_id={$webhookEvent['id']}, event_id={$this->event_id}, type={$eventType}");

    $this->setInputParameters($payload, $eventType);

    $status = 'error';
    $message = '';

    try {
      $this->processWebhookEvent($payload, $eventType);
      $status = 'success';
      $message = 'Processed successfully';
    }
    catch (CRM_Core_Payment_SquareRetryableException $e) {
      if ($this->isWithinRetryWindow($webhookEvent)) {
        $status = 'new';
        $message = 'Will retry: ' . $e->getMessage();
        Civi::log()->warning("Square IPN: processQueuedWebhookEvent transient failure, will retry. EventID: {$this->event_id}: " . $e->getMessage());
      }
      else {
        $message = 'Gave up retrying: ' . $e->getMessage();
        Civi::log()->error("Square IPN: processQueuedWebhookEvent still failing after the retry window, giving up. EventID: {$this->event_id}: " . $e->getMessage());
      }
    }
    catch (\Throwable $e) {
      // Catches Error/TypeError too, not just Exception — the scheduled job
      // does not catch anything itself, so an escaping error would abort it
      // and leave this and every later row stuck in 'processing'.
      $message = $e->getMessage() . "\n" . $e->getTraceAsString();
      Civi::log()->error("Square IPN: processQueuedWebhookEvent failed. EventID: {$this->event_id}: " . $e->getMessage());
    }

    $update = PaymentprocessorWebhook::update(FALSE)
      ->addWhere('id', '=', $webhookEvent['id'])
      ->addValue('status', $status)
      ->addValue('message', preg_replace('/^(.{250}).*/su', '$1 ...', $message));
    if ($status === 'success') {
      $update->addValue('processed_date', 'now');
    }
    $update->execute();

    return $status !== 'error';
  }

  /**
   * Whether a queued event is still young enough to be retried.
   *
   * @param array $webhookEvent
   *
   * @return bool
   */
  protected function isWithinRetryWindow(array $webhookEvent): bool {
    $created = empty($webhookEvent['created_date']) ? FALSE : strtotime($webhookEvent['created_date']);
    return $created === FALSE || (time() - $created) < self::RETRY_WINDOW_SECONDS;
  }

  /**
   * Build a queue identifier for related webhook events.
   *
   * @return string
   */
  private function getWebhookIdentifier(): string {
    return implode(':', [
      $this->payment_id ?? '',
      $this->invoice_id ?? '',
      $this->subscription_id ?? '',
    ]);
  }

  /**
   * Extract key identifiers from the payload for use during processing.
   *
   * @param array $payload
   *   Decoded JSON webhook payload.
   * @param string $eventType
   *   Square event type string.
   */
  public function setInputParameters(array $payload, string $eventType): void {
    $obj = $payload['data']['object'] ?? [];

    $this->event_type = $eventType;

    $this->subscription_id = $obj['subscription']['id']
      ?? $obj['invoice']['subscription_id']
      ?? NULL;

    $this->invoice_id = $obj['invoice']['id'] ?? NULL;

    $this->customer_id = $obj['subscription']['customer_id']
      ?? $obj['invoice']['primary_recipient']['customer_id']
      ?? $obj['payment']['customer_id']
      ?? NULL;

    $this->payment_id = $obj['payment']['id']
      ?? $obj['refund']['payment_id']
      ?? NULL;
  }

  /**
   * Route the webhook event to the appropriate handler.
   *
   * @param array $payload
   *   Decoded JSON webhook payload.
   * @param string $eventType
   *   Square event type string.
   *
   * @return bool TRUE on success.
   *
   * @throws \Exception
   *   On processing failure.
   */
  public function processWebhookEvent(array $payload, string $eventType): bool {
    $obj = $payload['data']['object'] ?? [];

    CRM_Core_Payment_SquareDebugLogger::log("Square IPN: processWebhookEvent() dispatching event_id={$this->event_id}, type={$eventType}, subscription_id=" . ($this->subscription_id ?? 'null') . ", invoice_id=" . ($this->invoice_id ?? 'null') . ", payment_id=" . ($this->payment_id ?? 'null'));

    switch ($eventType) {

      case 'subscription.created':
        if (!empty($this->subscription_id)) {
          $this->_paymentProcessor->syncSubscriptionFromSquare($this->subscription_id);
          CRM_Core_Payment_SquareDebugLogger::log("Square IPN: subscription.created synced for {$this->subscription_id}");
        }
        break;

      case 'subscription.updated':
        if (!empty($this->subscription_id)) {
          $this->_paymentProcessor->syncSubscriptionFromSquare($this->subscription_id);
          CRM_Core_Payment_SquareDebugLogger::log("Square IPN: subscription.updated synced for {$this->subscription_id}");
        }
        break;

      case 'subscription.canceled':
        if (!empty($this->subscription_id)) {
          $this->_paymentProcessor->syncSubscriptionCancellationFromSquare($this->subscription_id);
          CRM_Core_Payment_SquareDebugLogger::log("Square IPN: subscription.canceled synced for {$this->subscription_id}");
        }
        break;

      case 'invoice.created':
        // Nothing to record. Square publishes invoice.created while the
        // invoice is still a DRAFT it has not tried to charge, so it is never
        // evidence of payment — and it must not create a contribution
        // either: the first invoice of a subscription belongs to the Pending
        // contribution CiviCRM created at checkout. The installment is
        // recorded once Square confirms the charge, via invoice.payment_made
        // or payment.updated (see
        // CRM_Core_Payment_Square::recordSubscriptionInstallment()).
        CRM_Core_Payment_SquareDebugLogger::log("Square IPN: invoice.created noted for invoice {$this->invoice_id} (subscription " . ($this->subscription_id ?? 'null') . '); nothing is recorded until Square confirms payment.');
        break;

      case 'invoice.payment_made':
        $this->_paymentProcessor->handleInvoicePaymentCreated($payload);
        CRM_Core_Payment_SquareDebugLogger::log("Square IPN: invoice.payment_made processed for invoice {$this->invoice_id}");
        break;

      case 'invoice.payment_failed':
        $invoice = $obj['invoice'] ?? [];
        if (!empty($invoice)) {
          $this->_paymentProcessor->syncInvoicePaymentFailedFromSquare($invoice);
        }
        break;

      case 'payment.updated':
        $payment = $obj['payment'] ?? [];
        if (!empty($payment)) {
          $this->_paymentProcessor->syncPaymentFromSquare($payment);
          CRM_Core_Payment_SquareDebugLogger::log("Square IPN: payment.updated synced for {$this->payment_id}");
        }
        break;

      case 'refund.created':
      case 'refund.updated':
        $refund = $obj['refund'] ?? [];
        if (!empty($refund)) {
          $this->_paymentProcessor->syncRefundFromSquare($refund);
          CRM_Core_Payment_SquareDebugLogger::log("Square IPN: {$eventType} synced for payment {$this->payment_id}");
        }
        break;

      default:
        CRM_Core_Payment_SquareDebugLogger::log("Square IPN: unhandled event type '{$eventType}'");
        break;
    }

    return TRUE;
  }

  /**
   * Set the raw IPN data.
   *
   * @param Object|array|string $data
   */
  public function setData(object|array|string $data) {
    $this->data = $data;
  }

  /**
   * Get the raw IPN data.
   *
   * @return object|array|string
   */
  public function getData(): object|array|string {
    return $this->data;
  }

}
