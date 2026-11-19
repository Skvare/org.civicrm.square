<?php

use Square\SquareClient;
use Square\Events\Requests\SearchEventsRequest;
use Square\Types\SearchEventsFilter;
use Square\Types\SearchEventsQuery;
use Square\Types\SearchEventsSort;
use Square\Types\TimeRange;

/**
 * Recovers Square events whose webhooks never reached a payment processor.
 *
 * Square's Events API returns the same events it sends as webhooks, with
 * the same event IDs. Each one CiviCRM has not received is handed to
 * CRM_Core_Payment_SquareIPN exactly as if its webhook had just arrived, so
 * it is recorded in the webhook queue and processed; one already received
 * (in any queue state) is skipped. Events still in 'error' are not
 * reprocessed: use the webhook queue's Retry action for those.
 *
 * Two limits are Square's: events can only be searched for 28 days, and
 * only once event search is enabled for the application (enableSearch()),
 * and then only events that happened since.
 */
class CRM_Square_EventRecovery {

  /**
   * How far back Square keeps events searchable, in days.
   */
  public const SEARCH_WINDOW_DAYS = 28;

  /**
   * Events newer than this, in seconds, are left to their webhooks.
   *
   * Their delivery may still be under way. Nothing would be recorded twice
   * if not, but there is no need to race it.
   */
  public const SETTLE_SECONDS = 600;

  /**
   * Events requested per page.
   */
  public const PAGE_SIZE = 100;

  /**
   * The payment processor the events are for.
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
   * Builds the event recovery for one payment processor.
   *
   * @param \CRM_Core_Payment_Square $processor
   * @param \CRM_Square_Gateway $gateway
   */
  public function __construct(CRM_Core_Payment_Square $processor, CRM_Square_Gateway $gateway) {
    $this->processor = $processor;
    $this->gateway = $gateway;
  }

  /**
   * Make the application's events searchable, from now on.
   *
   * Square has no way to ask whether search is enabled, so when it was
   * first enabled from CiviCRM is remembered in the
   * square_event_search_enabled setting.
   *
   * @throws \CRM_Core_Exception
   */
  public function enableSearch(): void {
    $this->gateway->call(fn (SquareClient $client) => $client->events->enableEvents());
    $enabled = (array) (Civi::settings()->get('square_event_search_enabled') ?: []);
    $processorId = $this->gateway->processorId();
    if (empty($enabled[$processorId])) {
      $enabled[$processorId] = date('Y-m-d H:i:s');
      Civi::settings()->set('square_event_search_enabled', $enabled);
    }
  }

  /**
   * When event search was first enabled from CiviCRM for this processor.
   *
   * @return string|null
   *   Y-m-d H:i:s, or NULL if it never was.
   */
  public function getSearchEnabledSince(): ?string {
    $enabled = (array) (Civi::settings()->get('square_event_search_enabled') ?: []);
    return $enabled[$this->gateway->processorId()] ?? NULL;
  }

  /**
   * Fetch the events Square created since a time, and receive any CiviCRM missed.
   *
   * @param string $since
   *   A strtotime() date or relative time, e.g. "-3 days". Never earlier
   *   than Square keeps events.
   * @param int $limit
   *   The most events to look at in one run.
   *
   * @return array
   *   Counts: found (events Square returned), processed (received now),
   *   duplicate (already received), and other (unsupported or invalid).
   *
   * @throws \CRM_Core_Exception
   *   If Square cannot be searched.
   */
  public function recover(string $since = '-3 days', int $limit = 500): array {
    $counts = ['found' => 0, 'processed' => 0, 'duplicate' => 0, 'other' => 0];
    $now = $this->now();
    $start = strtotime($since, $now);
    if ($start === FALSE) {
      throw new CRM_Core_Exception("Cannot read the date '{$since}'.");
    }

    foreach ($this->search($start, $now, $limit) as $json) {
      $counts['found']++;
      $outcome = $this->receive($json);
      if ($outcome === CRM_Core_Payment_SquareIPN::RECEIVED_PROCESSED) {
        $counts['processed']++;
      }
      elseif ($outcome === CRM_Core_Payment_SquareIPN::RECEIVED_DUPLICATE) {
        $counts['duplicate']++;
      }
      else {
        $counts['other']++;
      }
    }

    if ($counts['processed']) {
      Civi::log('square')->warning("Square: recovered {$counts['processed']} event(s) whose webhooks never reached payment processor {$this->gateway->processorId()}. Check its Square webhook subscription.");
    }
    return $counts;
  }

  /**
   * The events, of the types CiviCRM handles, Square created in a time range.
   *
   * The range is narrowed to what Square can find: the last 28 days, since
   * search was enabled, and not the last few minutes (see SETTLE_SECONDS).
   *
   * @param int $start
   *   Unix timestamp.
   * @param int $end
   *   Unix timestamp.
   * @param int $limit
   *   The most events to return.
   *
   * @return string[]
   *   Each event as Square would send it in a webhook (JSON), oldest first.
   *
   * @throws \CRM_Core_Exception
   *   If Square cannot be searched.
   */
  public function search(int $start, int $end, int $limit): array {
    [$start, $end] = $this->searchableRange($start, $end);
    if ($start >= $end || $limit <= 0) {
      return [];
    }

    $request = new SearchEventsRequest([
      'limit' => min(self::PAGE_SIZE, $limit),
      'query' => new SearchEventsQuery([
        'filter' => new SearchEventsFilter([
          'eventTypes' => CRM_Core_Payment_SquareIPN::getSupportedEventTypes(),
          'createdAt' => new TimeRange([
            'startAt' => gmdate('Y-m-d\TH:i:s\Z', $start),
            'endAt' => gmdate('Y-m-d\TH:i:s\Z', $end),
          ]),
        ]),
        // Oldest first, as webhooks arrive.
        'sort' => new SearchEventsSort(['field' => 'DEFAULT', 'order' => 'ASC']),
      ]),
    ]);

    $events = [];
    do {
      $response = $this->gateway->call(fn (SquareClient $client) => $client->events->searchEvents($request));
      foreach ($response->getEvents() ?? [] as $event) {
        if (count($events) >= $limit) {
          break 2;
        }
        $events[] = $event->toJson();
      }
      $cursor = $response->getCursor();
      // Later pages need the original query, with the cursor.
      $request->setCursor($cursor);
    } while (!empty($cursor));
    return $events;
  }

  /**
   * Narrow a time range to the part Square can search.
   *
   * @param int $start
   * @param int $end
   *
   * @return int[]
   *   [start, end], as Unix timestamps.
   */
  protected function searchableRange(int $start, int $end): array {
    $now = $this->now();
    // A minute's margin, so the oldest bound is not already out of range by
    // the time Square reads it.
    $start = max($start, $now - self::SEARCH_WINDOW_DAYS * 86400 + 60);
    // Nothing from before search was enabled can be found.
    $enabledSince = $this->getSearchEnabledSince();
    if ($enabledSince !== NULL && strtotime($enabledSince) !== FALSE) {
      $start = max($start, (int) strtotime($enabledSince));
    }
    return [$start, min($end, $now - self::SETTLE_SECONDS)];
  }

  /**
   * Receive one event as if its webhook had just arrived.
   *
   * @param string $json
   *   The event, as Square would send it in a webhook.
   *
   * @return string
   *   One of CRM_Core_Payment_SquareIPN's RECEIVED_* constants.
   */
  protected function receive(string $json): string {
    $payload = json_decode($json, TRUE);
    if (!is_array($payload)) {
      return CRM_Core_Payment_SquareIPN::RECEIVED_INVALID;
    }
    $ipn = new CRM_Core_Payment_SquareIPN($this->processor);
    $ipn->setData($json);
    return $ipn->receiveEvent($payload);
  }

  /**
   * The current time.
   *
   * @return int
   */
  protected function now(): int {
    return time();
  }

}
