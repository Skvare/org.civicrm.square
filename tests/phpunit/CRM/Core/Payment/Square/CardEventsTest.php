<?php

require_once __DIR__ . '/SquareLedgerTestCase.php';

/**
 * Square card.* webhooks: a card on file disabled, forgotten or updated.
 *
 * A recurring contribution's payment token holds the Square card ID its
 * subscription charges. Square disabling or forgetting that card is not
 * recorded on any contribution — the next installment fails, and
 * invoice.scheduled_charge_failed records that — but staff are warned which
 * series will fail. Square's card updater keeps the card ID, so only the
 * expiry date and last 4 digits CiviCRM shows are updated.
 */
class CRM_Core_Payment_Square_CardEventsTest extends CRM_Core_Payment_Square_SquareLedgerTestCase {

  private const CARD_ID = 'ccof:CA4SEFakeCardOnFileForTests1234';

  private const TOKEN_ID = 50;

  /**
   * The checkout's series charges the card, saved as this processor's payment token.
   */
  protected function setUp(): void {
    parent::setUp();
    $this->processor->paymentTokens[self::TOKEN_ID] = [
      'id' => self::TOKEN_ID,
      'contact_id' => 7,
      'payment_processor_id' => self::PROCESSOR_ID,
      'token' => self::CARD_ID,
      'expiry_date' => '2026-12-31 00:00:00',
      'masked_account_number' => '1111',
    ];
    $this->processor->recurs[self::RECUR_ID]['payment_token_id'] = self::TOKEN_ID;
    $this->processor->recurs[self::RECUR_ID]['contribution_status_id'] = CRM_Square_Status::recurStatusId('In Progress');
  }

  /**
   * Leave debug logging off for the next test.
   */
  protected function tearDown(): void {
    unset(Civi::$settings['square_ipn_debug_logging']);
    parent::tearDown();
  }

  /**
   * The card events are routed, not ignored as unsupported.
   */
  public function testCardEventsAreSupported(): void {
    $supported = CRM_Core_Payment_SquareIPN::getSupportedEventTypes();
    foreach (['card.disabled', 'card.forgotten', 'card.automatically_updated', 'card.updated'] as $type) {
      $this->assertContains($type, $supported);
    }
  }

  /**
   * A disabled card still charged by an active series warns staff, naming the series.
   *
   * @dataProvider unusableCardEvents
   */
  public function testUnusableCardOfAnActiveSeriesIsWarned(string $eventType, string $how): void {
    $before = $this->processor->recurs;
    $contributions = $this->processor->contributions;
    Civi::$settings['square_ipn_debug_logging'] = TRUE;

    $this->deliver($this->cardEvent($eventType, ['enabled' => FALSE]));

    $warnings = $this->logged('warning');
    $this->assertCount(1, $warnings);
    $this->assertStringContainsString("card …1234 of contact 7 was {$how}", $warnings[0]);
    $this->assertStringContainsString('Recurring contribution(s) ' . self::RECUR_ID . ' still charge it', $warnings[0]);

    // Nothing is recorded: the failed installment will be, if Square tries it.
    $this->assertSame($before, $this->processor->recurs);
    $this->assertSame($contributions, $this->processor->contributions);

    // The card ID is a reusable payment token: never logged in full, debug included.
    foreach (Civi::$logged as [$level, $message]) {
      $this->assertStringNotContainsString(self::CARD_ID, $message, "{$level} log message");
    }
  }

  /**
   * @return array
   */
  public static function unusableCardEvents(): array {
    return [
      'card.disabled' => ['card.disabled', 'disabled'],
      'card.forgotten' => ['card.forgotten', "forgotten (deleted at the customer's request)"],
    ];
  }

  /**
   * Every active series charging the card is named; ended ones are not.
   */
  public function testOnlyActiveSeriesAreNamed(): void {
    foreach ([46 => 'Overdue', 47 => 'Cancelled', 48 => 'Completed'] as $recurId => $status) {
      $this->processor->recurs[$recurId] = [
        'id' => $recurId,
        'contribution_status_id' => CRM_Square_Status::recurStatusId($status),
      ] + $this->processor->recurs[self::RECUR_ID];
    }

    $this->deliver($this->cardEvent('card.disabled', ['enabled' => FALSE]));

    $warnings = $this->logged('warning');
    $this->assertCount(1, $warnings);
    $this->assertStringContainsString('Recurring contribution(s) ' . self::RECUR_ID . ', 46 still charge it', $warnings[0]);
  }

  /**
   * A disabled card no active series charges needs no one's attention.
   */
  public function testUnusableCardOfAnEndedSeriesIsNotWarned(): void {
    $this->processor->recurs[self::RECUR_ID]['contribution_status_id'] = CRM_Square_Status::recurStatusId('Cancelled');

    $this->deliver($this->cardEvent('card.disabled', ['enabled' => FALSE]));

    $this->assertSame([], Civi::$logged);
  }

  /**
   * A card saved by another processor, or not saved at all, is not this processor's.
   *
   * @dataProvider cardsOfOthers
   */
  public function testCardOfAnotherProcessorIsIgnored(string $cardId, int $tokenProcessorId): void {
    $this->processor->paymentTokens[self::TOKEN_ID]['payment_processor_id'] = $tokenProcessorId;
    $before = $this->processor->paymentTokens;

    $this->deliver($this->cardEvent('card.disabled', ['id' => $cardId, 'enabled' => FALSE]));
    $this->deliver($this->cardEvent('card.automatically_updated', [
      'id' => $cardId,
      'exp_month' => 3,
      'exp_year' => 2030,
      'last_4' => '4242',
    ]));

    $this->assertSame([], Civi::$logged);
    $this->assertSame($before, $this->processor->paymentTokens);
  }

  /**
   * @return array
   */
  public static function cardsOfOthers(): array {
    return [
      'saved by the sandbox processor' => [self::CARD_ID, 43],
      'not saved in CiviCRM' => ['ccof:SomeOtherCard9999', self::PROCESSOR_ID],
    ];
  }

  /**
   * Square's card updater renews the card: the token's expiry and last 4 follow.
   *
   * @dataProvider cardUpdateEvents
   */
  public function testUpdatedCardUpdatesThePaymentToken(string $eventType): void {
    $this->deliver($this->cardEvent($eventType, ['exp_month' => 3, 'exp_year' => 2030, 'last_4' => '4242']));

    $token = $this->processor->paymentTokens[self::TOKEN_ID];
    $this->assertSame('2030-03-31', $token['expiry_date']);
    $this->assertSame('4242', $token['masked_account_number']);
    $this->assertSame(self::CARD_ID, $token['token'], 'The card ID, which the subscription charges, stays the same.');
    $this->assertSame([], $this->logged('warning'));
  }

  /**
   * @return array
   */
  public static function cardUpdateEvents(): array {
    return [
      'card.automatically_updated' => ['card.automatically_updated'],
      'card.updated' => ['card.updated'],
    ];
  }

  /**
   * An update that changes neither shown detail writes nothing.
   */
  public function testUpdateWithTheSameDetailsWritesNothing(): void {
    $before = $this->processor->paymentTokens;

    // Stored as a datetime; Square reports month and year.
    $this->deliver($this->cardEvent('card.updated', ['exp_month' => 12, 'exp_year' => 2026, 'last_4' => '1111']));

    $this->assertSame($before, $this->processor->paymentTokens);
  }

  /**
   * An update without expiry or last 4 leaves them as they are.
   */
  public function testUpdateWithoutDetailsKeepsThem(): void {
    $before = $this->processor->paymentTokens;

    $this->deliver($this->cardEvent('card.updated', [
      'exp_month' => NULL,
      'exp_year' => NULL,
      'last_4' => NULL,
      'billing_address' => ['postal_code' => '80003'],
    ]));

    $this->assertSame($before, $this->processor->paymentTokens);
  }

  /**
   * A card.* webhook payload, shaped as Square sends it.
   *
   * @param string $type
   * @param array $card
   *   Fields to set on the card object.
   *
   * @return array
   */
  private function cardEvent(string $type, array $card): array {
    $card += [
      'id' => self::CARD_ID,
      'card_brand' => 'VISA',
      'last_4' => '1111',
      'exp_month' => 12,
      'exp_year' => 2026,
      'customer_id' => self::CUSTOMER_ID,
      'enabled' => TRUE,
    ];
    return [
      'type' => $type,
      'event_id' => bin2hex(random_bytes(8)),
      'data' => ['type' => 'card', 'id' => $card['id'], 'object' => ['card' => $card]],
    ];
  }

  /**
   * Messages logged at a level during this test.
   *
   * @param string $level
   *
   * @return string[]
   */
  private function logged(string $level): array {
    return array_values(array_map(fn (array $entry) => $entry[1], array_filter(Civi::$logged, fn (array $entry) => $entry[0] === $level)));
  }

}
