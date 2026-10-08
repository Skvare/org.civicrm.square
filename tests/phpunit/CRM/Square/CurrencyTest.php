<?php

use PHPUnit\Framework\TestCase;

/**
 * Amounts in Square's smallest currency unit (CRM_Square_Currency).
 */
class CRM_Square_CurrencyTest extends TestCase {

  /**
   * @dataProvider amountProvider
   */
  public function testToAndFromMinorUnits(string $amount, string $currency, int $minorUnits): void {
    $this->assertSame($minorUnits, CRM_Square_Currency::toMinorUnits($amount, $currency));
    $this->assertSame((float) $amount, CRM_Square_Currency::fromMinorUnits($minorUnits, $currency));
  }

  public static function amountProvider(): array {
    return [
      'US dollars, in cents' => ['12.34', 'USD', 1234],
      'lower-case code' => ['12.34', 'usd', 1234],
      'yen, which has no minor unit' => ['100', 'JPY', 100],
      'Kuwaiti dinar, in fils' => ['1.234', 'KWD', 1234],
    ];
  }

  public function testAnUnknownCurrencyHasTwoDecimalPlaces(): void {
    $this->assertSame(2, CRM_Square_Currency::decimals(NULL));
    $this->assertSame(2, CRM_Square_Currency::decimals('XYZ'));
  }

  public function testRoundsToTheSmallestUnit(): void {
    // 0.1 + 0.2 is not exactly 0.3 in floating point.
    $this->assertSame(30, CRM_Square_Currency::toMinorUnits(0.1 + 0.2, 'USD'));
  }

}
