<?php

/**
 * Converts between CiviCRM amounts and Square's smallest currency units.
 *
 * Square amounts are integers in the currency's smallest unit: cents for
 * USD, but whole yen for JPY, which has no minor unit. Treating every
 * currency as having cents would charge ¥10,000 for ¥100.
 */
class CRM_Square_Currency {

  /**
   * ISO 4217 currencies whose minor unit is not two decimal places.
   */
  private const DECIMALS = [
    // No minor unit.
    'BIF' => 0,
    'CLP' => 0,
    'DJF' => 0,
    'GNF' => 0,
    'ISK' => 0,
    'JPY' => 0,
    'KMF' => 0,
    'KRW' => 0,
    'PYG' => 0,
    'RWF' => 0,
    'UGX' => 0,
    'UYI' => 0,
    'VND' => 0,
    'VUV' => 0,
    'XAF' => 0,
    'XOF' => 0,
    'XPF' => 0,
    // Three decimal places.
    'BHD' => 3,
    'IQD' => 3,
    'JOD' => 3,
    'KWD' => 3,
    'LYD' => 3,
    'OMR' => 3,
    'TND' => 3,
  ];

  /**
   * The number of decimal places in a currency's amounts.
   *
   * @param string|null $currency
   *   ISO 4217 code.
   *
   * @return int
   */
  public static function decimals(?string $currency): int {
    return self::DECIMALS[strtoupper(trim((string) $currency))] ?? 2;
  }

  /**
   * An amount in Square's smallest unit of the currency (e.g. cents).
   *
   * @param float|int|string $amount
   * @param string|null $currency
   *
   * @return int
   */
  public static function toMinorUnits($amount, ?string $currency): int {
    return (int) round((float) $amount * (10 ** self::decimals($currency)));
  }

  /**
   * An amount from Square's smallest unit of the currency.
   *
   * @param int|string $minorUnits
   * @param string|null $currency
   *
   * @return float
   */
  public static function fromMinorUnits($minorUnits, ?string $currency): float {
    return (float) $minorUnits / (10 ** self::decimals($currency));
  }

}
