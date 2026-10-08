<?php

/**
 * @file
 * Webhook queue state tests, with an in-memory webhook queue.
 */

require_once __DIR__ . '/SquareUnitTestCase.php';
require_once dirname(__DIR__, 6) . '/CRM/Core/Payment/SquareIPN.php';
require_once dirname(__DIR__, 6) . '/CRM/Core/Payment/SquareDebugLogger.php';

/**
 * CRM_Core_Payment_SquareIPN with an in-memory civicrm_paymentprocessor_webhook.
 */
class CRM_Core_Payment_Square_FakeQueueIPN extends CRM_Core_Payment_SquareIPN {

  /**
   * Queue records by ID.
   *
   * @var array
   */
  public array $records = [];

  /**
   * Values passed to each createQueueRecord() call.
   *
   * @var array
   */
  public array $created = [];

  /**
   * Whether the dedupe lock is free.
   *
   * @var bool
   */
  public bool $lockFree = TRUE;

  /**
   * @param string $name
   *
   * @return object|null
   */
  protected function acquireDedupeLock(string $name) {
    return $this->lockFree ? new class() {

      /**
       * Nothing to release.
       */
      public function release(): void {
      }

    } : NULL;
  }

  /**
   * @param int $processorId
   * @param string $eventId
   *
   * @return array|null
   */
  protected function findQueuedEvent(int $processorId, string $eventId): ?array {
    foreach ($this->records as $record) {
      if ($record['payment_processor_id'] === $processorId && $record['event_id'] === $eventId) {
        return $record;
      }
    }
    return NULL;
  }

  /**
   * @param array $values
   *
   * @return array
   */
  protected function createQueueRecord(array $values): array {
    $this->created[] = $values;
    $id = count($this->records) + 1;
    // The table's defaults.
    $this->records[$id] = $values + [
      'id' => $id,
      'status' => 'new',
      'processed_date' => NULL,
      'created_date' => date('Y-m-d H:i:s'),
    ];
    return $this->records[$id];
  }

  /**
   * @param int $id
   * @param array $values
   */
  protected function updateQueueRecord(int $id, array $values): void {
    $this->records[$id] = $values + $this->records[$id];
  }

}

/**
 * Webhook queue states: what is processed, retried, or left for a person.
 */
class CRM_Core_Payment_Square_WebhookQueueTest extends CRM_Core_Payment_Square_SquareUnitTestCase {

  /**
   * What the processor's payment.updated handler does.
   *
   * @var callable|null
   */
  private $onPayment = NULL;

  /**
   * Number of payment.updated events handled.
   *
   * @var int
   */
  private int $handled = 0;

  public function testNewEventIsRecordedAsNewThenProcessed(): void {
    $ipn = $this->ipn();
    $payload = $this->event();

    $this->assertTrue($this->receive($ipn, $payload));

    $this->assertSame(1, $this->handled);
    $this->assertCount(1, $ipn->created);
    $this->assertArrayNotHasKey('status', $ipn->created[0], "Recorded in the default 'new', so a request that dies mid-processing is still retried.");
    $this->assertSame('payment.updated', $ipn->created[0]['trigger']);
    $this->assertSame($payload['event_id'], $ipn->created[0]['event_id']);
    $this->assertSame('PAY-1::', $ipn->created[0]['identifier']);
    $record = $ipn->records[1];
    $this->assertSame('success', $record['status']);
    $this->assertSame('now', $record['processed_date']);
  }

  public function testRedeliveredEventIsNotProcessedAgain(): void {
    $ipn = $this->ipn();
    $payload = $this->event();
    $this->receive($ipn, $payload);

    $this->assertTrue($this->receive($ipn, $payload));

    $this->assertSame(1, $this->handled);
    $this->assertCount(1, $ipn->records);
  }

  public function testEventBeingRecordedElsewhereIsTreatedAsADuplicate(): void {
    $ipn = $this->ipn();
    $ipn->lockFree = FALSE;

    $this->assertTrue($this->receive($ipn, $this->event()));

    $this->assertSame([], $ipn->records);
    $this->assertSame(0, $this->handled);
  }

  public function testUnsupportedEventIsAcknowledgedButNotRecorded(): void {
    $ipn = $this->ipn();

    $payload = ['type' => 'customer.created', 'event_id' => 'EVT-X', 'data' => ['object' => []]];
    $this->assertTrue($this->receive($ipn, $payload));

    $this->assertSame([], $ipn->records);
  }

  public function testEventWithoutAnIdIsRejected(): void {
    $ipn = $this->ipn();
    $payload = $this->event();
    unset($payload['event_id']);

    $this->assertFalse($this->receive($ipn, $payload));
    $this->assertSame([], $ipn->records);
  }

  /**
   * A transient failure is left in 'new' for the scheduled job.
   */
  public function testTransientFailureIsLeftForRetry(): void {
    $this->onPayment = fn () => throw new CRM_Core_Payment_SquareRetryableException('Square API returned HTTP 503.');
    $ipn = $this->ipn();

    $this->assertTrue($this->receive($ipn, $this->event()), 'Accepted: it is queued for retry.');

    $record = $ipn->records[1];
    $this->assertSame('new', $record['status']);
    $this->assertNull($record['processed_date'], 'The job only retries records with no processed_date.');
    $this->assertStringContainsString('HTTP 503', $record['message']);
  }

  /**
   * The job retries 'new' forever, so the retry window ends it.
   */
  public function testTransientFailurePastTheRetryWindowIsAnError(): void {
    $this->onPayment = fn () => throw new CRM_Core_Payment_SquareRetryableException('still not there');
    $ipn = $this->ipn();
    $record = $this->queued($ipn, date('Y-m-d H:i:s', time() - CRM_Core_Payment_SquareIPN::RETRY_WINDOW_SECONDS - 60));

    $this->assertFalse($ipn->processQueuedWebhookEvent($record));

    $this->assertSame('error', $ipn->records[$record['id']]['status']);
    $this->assertStringContainsString('Gave up retrying', $ipn->records[$record['id']]['message']);
  }

  /**
   * A retry from the scheduled job, whose record holds the JSON body.
   */
  public function testQueuedEventIsProcessedByTheScheduledJob(): void {
    $ipn = $this->ipn();
    $record = $this->queued($ipn, date('Y-m-d H:i:s'));

    $this->assertTrue($ipn->processQueuedWebhookEvent($record));

    $this->assertSame(1, $this->handled);
    $this->assertSame('success', $ipn->records[$record['id']]['status']);
  }

  /**
   * @dataProvider permanentFailureProvider
   */
  public function testPermanentFailureIsAnError(\Throwable $failure): void {
    $this->onPayment = fn () => throw $failure;
    $ipn = $this->ipn();

    $this->assertFalse($this->receive($ipn, $this->event()));

    $record = $ipn->records[1];
    $this->assertSame('error', $record['status']);
    $this->assertNull($record['processed_date']);
    $this->assertStringContainsString($failure->getMessage(), $record['message']);
  }

  public static function permanentFailureProvider(): array {
    return [
      'reconciliation error' => [new CRM_Core_Exception('needs manual reconciliation')],
      // The scheduled job catches nothing itself, so an Error must not escape.
      'PHP error' => [new TypeError('Argument #1 must be of type array')],
    ];
  }

  /**
   * An IPN for payment processor 42 whose payment.updated handler is $this->onPayment.
   *
   * @return \CRM_Core_Payment_Square_FakeQueueIPN
   */
  private function ipn(): CRM_Core_Payment_Square_FakeQueueIPN {
    $test = $this;
    $processor = new class($test) {

      /**
       * @var \CRM_Core_Payment_Square_WebhookQueueTest
       */
      private $test;

      /**
       * @param \CRM_Core_Payment_Square_WebhookQueueTest $test
       */
      public function __construct($test) {
        $this->test = $test;
      }

      /**
       * @return int
       */
      public function getID() {
        return 42;
      }

      /**
       * @param array $payment
       */
      public function syncPaymentFromSquare(array $payment): void {
        $this->test->handlePayment($payment);
      }

    };
    return new CRM_Core_Payment_Square_FakeQueueIPN($processor);
  }

  /**
   * The processor's payment.updated handler.
   *
   * @param array $payment
   */
  public function handlePayment(array $payment): void {
    $this->handled++;
    if ($this->onPayment) {
      ($this->onPayment)($payment);
    }
  }

  /**
   * Receive a webhook, as CRM_Core_Payment_Square::handlePaymentNotification() does.
   *
   * @param \CRM_Core_Payment_Square_FakeQueueIPN $ipn
   * @param array $payload
   *
   * @return bool
   */
  private function receive(CRM_Core_Payment_Square_FakeQueueIPN $ipn, array $payload): bool {
    $ipn->setData(json_encode($payload));
    return $ipn->onReceiveWebhook($payload);
  }

  /**
   * A payment.updated event already in the queue, as the scheduled job reads it.
   *
   * @param \CRM_Core_Payment_Square_FakeQueueIPN $ipn
   * @param string $createdDate
   *
   * @return array
   */
  private function queued(CRM_Core_Payment_Square_FakeQueueIPN $ipn, string $createdDate): array {
    $payload = $this->event();
    $id = count($ipn->records) + 1;
    $ipn->records[$id] = [
      'id' => $id,
      'payment_processor_id' => 42,
      'event_id' => $payload['event_id'],
      'trigger' => $payload['type'],
      'data' => json_encode($payload),
      'status' => 'processing',
      'processed_date' => NULL,
      'created_date' => $createdDate,
    ];
    return $ipn->records[$id];
  }

  /**
   * @return array
   */
  private function event(): array {
    return [
      'type' => 'payment.updated',
      'event_id' => 'EVT-1',
      'data' => ['type' => 'payment', 'object' => ['payment' => ['id' => 'PAY-1', 'status' => 'COMPLETED']]],
    ];
  }

}
