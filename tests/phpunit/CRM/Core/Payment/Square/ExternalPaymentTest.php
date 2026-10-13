<?php

require_once __DIR__ . '/SquareLedgerTestCase.php';

/**
 * Square payments CiviCRM did not take: point of sale, Square Online, etc.
 *
 * Square sends their webhooks too. They are imported as contributions only
 * when the square_import_external_payments setting is on, and only when
 * taken at the processor's own location.
 */
class CRM_Core_Payment_Square_ExternalPaymentTest extends CRM_Core_Payment_Square_SquareLedgerTestCase {

  protected function setUp(): void {
    parent::setUp();
    unset(Civi::$settings['square_import_external_payments']);
    $this->processor = $this->ledgerProcessor(['signature' => self::LOCATION_ID]);
    $this->seedCheckout($this->processor);
  }

  protected function tearDown(): void {
    unset(Civi::$settings['square_import_external_payments']);
    parent::tearDown();
  }

  public function testExternalPaymentIsIgnoredByDefault(): void {
    $this->deliver($this->externalPaymentEvent(self::LOCATION_ID));

    $this->assertSame([], $this->processor->createCalls);
    $this->assertSame([], $this->processor->payments);
    $this->assertSame([], $this->errorsLogged(), 'Routine: nothing for anyone to do.');
  }

  /**
   * A CiviCRM checkout took it, but has no contribution for it.
   *
   * E.g. an event registration whose charge Square did not confirm.
   */
  public function testIgnoredPaymentFromACiviCrmCheckoutIsLoggedAsAnError(): void {
    $this->deliver($this->externalPaymentEvent(self::LOCATION_ID, 'd41d8cd98f00b204e9800998ecf8427e'));

    $this->assertSame([], $this->processor->createCalls);
    $errors = $this->errorsLogged();
    $this->assertCount(1, $errors);
    $this->assertStringContainsString('PAY-EXTERNAL (25.00 USD)', $errors[0]);
    $this->assertStringContainsString('d41d8cd98f00b204e9800998ecf8427e', $errors[0]);
  }

  public function testPaymentAtAnotherLocationIsIgnored(): void {
    Civi::$settings['square_import_external_payments'] = TRUE;

    $this->deliver($this->externalPaymentEvent('L-TOURNAMENT-POS'));

    $this->assertSame([], $this->processor->createCalls);
  }

  public function testPaymentAtThisLocationIsImportedWhenEnabled(): void {
    Civi::$settings['square_import_external_payments'] = TRUE;

    $this->deliver($this->externalPaymentEvent(self::LOCATION_ID));

    $this->assertCount(1, $this->processor->createCalls);
    $this->assertSame('PAY-EXTERNAL', $this->processor->createCalls[0]['trxn_id']);
    $this->assertSame(25.0, $this->processor->createCalls[0]['total_amount']);
    $payments = array_values(array_filter($this->processor->payments, fn ($payment) => $payment['trxn_id'] === 'PAY-EXTERNAL'));
    $this->assertCount(1, $payments);
  }

  /**
   * Messages logged at level error.
   *
   * @return string[]
   */
  private function errorsLogged(): array {
    return array_values(array_map(fn (array $log) => $log[1], array_filter(Civi::$logged, fn (array $log) => $log[0] === 'error')));
  }

  /**
   * A payment.updated for a card payment taken outside CiviCRM, two hours ago.
   *
   * @param string $locationId
   * @param string|null $referenceId
   *
   * @return array
   */
  private function externalPaymentEvent(string $locationId, ?string $referenceId = NULL): array {
    $createdAt = gmdate('Y-m-d\TH:i:s\Z', time() - 7200);
    return [
      'type' => 'payment.updated',
      'event_id' => 'EVENT-EXTERNAL',
      'data' => [
        'type' => 'payment',
        'id' => 'PAY-EXTERNAL',
        'object' => [
          'payment' => [
            'id' => 'PAY-EXTERNAL',
            'status' => 'COMPLETED',
            'source_type' => 'CARD',
            'location_id' => $locationId,
            'amount_money' => ['amount' => 2500, 'currency' => 'USD'],
            'buyer_email_address' => 'pat@example.org',
            'reference_id' => $referenceId,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
          ],
        ],
      ],
    ];
  }

}
