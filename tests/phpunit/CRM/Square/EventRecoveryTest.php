<?php

require_once dirname(__DIR__) . '/Core/Payment/Square/SquareUnitTestCase.php';

use Square\Events\EventsClient;
use Square\Events\Requests\SearchEventsRequest;
use Square\SquareClient;
use Square\Types\EnableEventsResponse;
use Square\Types\Event;
use Square\Types\EventData;
use Square\Types\SearchEventsResponse;

/**
 * Recovering Square events whose webhooks never arrived.
 */
class CRM_Square_EventRecoveryTest extends CRM_Core_Payment_Square_SquareUnitTestCase {

  /**
   * The time recovery runs at: 2026-11-18 12:00:00 UTC.
   */
  private const NOW = 1795003200;

  /**
   * Pages of events (mocked) Square returns, in order.
   *
   * @var \Square\Types\Event[][]
   */
  private array $pages = [];

  /**
   * Search requests sent to (mocked) Square, with the cursor each had.
   *
   * @var array
   */
  private array $searches = [];

  /**
   * Events handed to the webhook handler, as decoded JSON.
   *
   * @var array[]
   */
  public array $received = [];

  /**
   * Event IDs the webhook handler has already received.
   *
   * @var string[]
   */
  public array $alreadyReceived = [];

  protected function setUp(): void {
    parent::setUp();
    Civi::$settings = ['square_event_search_enabled' => [42 => '2026-11-01 00:00:00']];
    Civi::$logged = [];
    $this->pages = [];
    $this->searches = [];
    $this->received = [];
    $this->alreadyReceived = [];
  }

  public function testMissedEventsAreReceivedAndKnownOnesSkipped(): void {
    $this->pages = [[$this->event('EVT-1', 'payment.updated'), $this->event('EVT-2', 'refund.updated')]];
    $this->alreadyReceived = ['EVT-2'];

    $counts = $this->recovery()->recover('-3 days');

    $this->assertSame(['found' => 2, 'processed' => 1, 'duplicate' => 1, 'other' => 0], $counts);
    $this->assertSame(['EVT-1', 'EVT-2'], array_column($this->received, 'event_id'));
    // In the shape of a webhook, so the handler cannot tell the difference.
    $this->assertSame('payment.updated', $this->received[0]['type']);
    $this->assertSame(['payment' => ['id' => 'PAY-EVT-1']], $this->received[0]['data']['object']);
    $this->assertLogged('warning', 'recovered 1 event(s)');
  }

  public function testSearchAsksForSupportedEventsOldestFirstWithinTheWindow(): void {
    $this->recovery()->recover('-3 days');

    /** @var \Square\Events\Requests\SearchEventsRequest $request */
    $request = $this->searches[0]['request'];
    $filter = $request->getQuery()->getFilter();
    $this->assertSame(CRM_Core_Payment_SquareIPN::getSupportedEventTypes(), $filter->getEventTypes());
    $this->assertSame('2026-11-15T12:00:00Z', $filter->getCreatedAt()->getStartAt());
    $this->assertSame('2026-11-18T11:50:00Z', $filter->getCreatedAt()->getEndAt(), 'Events from the last minutes are left to their webhooks.');
    $this->assertSame('ASC', $request->getQuery()->getSort()->getOrder());
    $this->assertNull($filter->getLocationIds(), 'Webhooks come from every location too.');
  }

  public function testSearchStartsNoEarlierThanSquareKeepsEventsNorBeforeSearchWasEnabled(): void {
    Civi::$settings['square_event_search_enabled'] = [42 => '2026-01-01 00:00:00'];
    $this->recovery()->recover('-90 days');
    $startAt = $this->searches[0]['request']->getQuery()->getFilter()->getCreatedAt()->getStartAt();
    $this->assertSame(gmdate('Y-m-d\TH:i:s\Z', self::NOW - 28 * 86400 + 60), $startAt);

    $this->searches = [];
    Civi::$settings['square_event_search_enabled'] = [42 => date('Y-m-d H:i:s', self::NOW - 86400)];
    $this->recovery()->recover('-3 days');
    $startAt = $this->searches[0]['request']->getQuery()->getFilter()->getCreatedAt()->getStartAt();
    $this->assertSame(gmdate('Y-m-d\TH:i:s\Z', self::NOW - 86400), $startAt);
  }

  public function testEveryPageIsFetchedWithItsCursor(): void {
    $this->pages = [
      [$this->event('EVT-1', 'payment.updated')],
      [$this->event('EVT-2', 'payment.updated')],
    ];

    $counts = $this->recovery()->recover('-3 days');

    $this->assertSame(2, $counts['processed']);
    $this->assertSame([NULL, 'cursor-1'], array_column($this->searches, 'cursor'));
  }

  public function testNoMoreThanTheLimitIsLookedAt(): void {
    $this->pages = [[
      $this->event('EVT-1', 'payment.updated'),
      $this->event('EVT-2', 'payment.updated'),
      $this->event('EVT-3', 'payment.updated'),
    ],
    ];

    $counts = $this->recovery()->recover('-3 days', 2);

    $this->assertSame(2, $counts['found']);
    $this->assertSame(['EVT-1', 'EVT-2'], array_column($this->received, 'event_id'));
  }

  public function testNothingIsSearchedForAnEmptyWindow(): void {
    $counts = $this->recovery()->recover('-5 minutes');

    $this->assertSame(0, $counts['found']);
    $this->assertSame([], $this->searches);
  }

  public function testEnablingSearchRemembersWhenItWasFirstEnabled(): void {
    Civi::$settings = [];
    $recovery = $this->recovery();
    $this->assertNull($recovery->getSearchEnabledSince());

    $recovery->enableSearch();
    $since = $recovery->getSearchEnabledSince();
    $this->assertNotNull($since);

    Civi::$settings['square_event_search_enabled'][42] = '2026-01-01 00:00:00';
    $recovery->enableSearch();
    $this->assertSame('2026-01-01 00:00:00', $recovery->getSearchEnabledSince(), 'Enabling it again keeps the first date.');
  }

  /**
   * A Square event, as the Events API returns it.
   *
   * @param string $eventId
   * @param string $type
   *
   * @return \Square\Types\Event
   */
  private function event(string $eventId, string $type): Event {
    return new Event([
      'merchantId' => 'MERCHANT',
      'type' => $type,
      'eventId' => $eventId,
      'createdAt' => '2026-11-17T10:00:00Z',
      'data' => new EventData([
        'type' => 'payment',
        'id' => "PAY-{$eventId}",
        'object' => ['payment' => ['id' => "PAY-{$eventId}"]],
      ]),
    ]);
  }

  /**
   * Event recovery for processor 42 at NOW, against the mocked Square.
   *
   * @return \CRM_Square_EventRecovery
   */
  private function recovery(): CRM_Square_EventRecovery {
    $config = $this->processorConfig();
    $events = $this->createMock(EventsClient::class);
    $events->method('searchEvents')->willReturnCallback(function (SearchEventsRequest $request) {
      $page = count($this->searches);
      $this->searches[] = ['request' => $request, 'cursor' => $request->getCursor()];
      return new SearchEventsResponse([
        'events' => $this->pages[$page] ?? [],
        'cursor' => isset($this->pages[$page + 1]) ? 'cursor-' . ($page + 1) : NULL,
      ]);
    });
    $events->method('enableEvents')->willReturn(new EnableEventsResponse([]));
    $client = new SquareClient(token: 'test-token', options: ['baseUrl' => 'https://example.invalid']);
    $client->events = $events;
    $gateway = new CRM_Square_Gateway($config, fn () => $client);
    $processor = new CRM_Core_Payment_Square('live', $config);

    return new class($processor, $gateway, $this) extends CRM_Square_EventRecovery {

      /**
       * @var \CRM_Square_EventRecoveryTest
       */
      private $test;

      /**
       * @param \CRM_Core_Payment_Square $processor
       * @param \CRM_Square_Gateway $gateway
       * @param \CRM_Square_EventRecoveryTest $test
       */
      public function __construct($processor, $gateway, $test) {
        parent::__construct($processor, $gateway);
        $this->test = $test;
      }

      /**
       * @param string $json
       *
       * @return string
       */
      protected function receive(string $json): string {
        $payload = json_decode($json, TRUE);
        $this->test->received[] = $payload;
        return in_array($payload['event_id'], $this->test->alreadyReceived, TRUE)
          ? CRM_Core_Payment_SquareIPN::RECEIVED_DUPLICATE
          : CRM_Core_Payment_SquareIPN::RECEIVED_PROCESSED;
      }

      /**
       * @return int
       */
      protected function now(): int {
        return 1795003200;
      }

    };
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
