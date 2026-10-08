<?php

/**
 * CiviCRM option values the Square extension needs, and Square-to-CiviCRM status mappings.
 *
 * Every ID is resolved by name, never hard-coded, since option values are
 * not stable across CiviCRM installs.
 */
class CRM_Square_Status {

  /**
   * Resolve a contribution_status_id by name without relying on installation-specific IDs.
   */
  public static function contributionStatusId(string $name): int {
    return self::pseudoConstantId('contribution_status_id', $name);
  }

  /**
   * Resolve a recurring contribution's contribution_status_id by name.
   *
   * Recurring contributions use their own option group
   * (contribution_recur_status), which has statuses — In Progress, Overdue,
   * Processing, Failing — that contribution_status does not, and gives
   * values 7 and 8 different meanings.
   */
  public static function recurStatusId(string $name): int {
    return self::pseudoConstantId('contribution_status_id', $name, 'CRM_Contribute_BAO_ContributionRecur');
  }

  /**
   * Resolve a payment_instrument_id by name without relying on installation-specific IDs.
   */
  public static function paymentInstrumentId(string $name): int {
    return self::pseudoConstantId('payment_instrument_id', $name);
  }

  /**
   * Resolve a financial_type_id by name without relying on installation-specific IDs.
   */
  public static function financialTypeId(string $name): int {
    return self::pseudoConstantId('financial_type_id', $name);
  }

  /**
   * Resolve a pseudoconstant value by name.
   *
   * Replaces the deprecated CRM_Contribute_PseudoConstant::contributionStatus()
   * (and avoids hard-coding installation-specific numeric IDs for statuses,
   * payment instruments, and financial types).
   *
   * @param string $field
   * @param string $name
   * @param string $baoName
   *   The entity whose field it is; Contribution unless given.
   */
  public static function pseudoConstantId(string $field, string $name, string $baoName = 'CRM_Contribute_BAO_Contribution'): int {
    $id = \CRM_Core_PseudoConstant::getKey($baoName, $field, $name);
    if ($id === FALSE || $id === NULL) {
      throw new \CRM_Core_Exception("CiviCRM {$field} '{$name}' is unavailable.");
    }
    return (int) $id;
  }

  /**
   * Map Square subscription statuses to a recurring contribution's contribution_status_id.
   *
   * @param string $squareStatus
   *
   * @return int|null
   */
  public static function mapSubscriptionStatus($squareStatus) {
    $squareStatus = strtoupper(trim($squareStatus));

    // Square subscription statuses per the Subscriptions API: PENDING,
    // ACTIVE, CANCELED, DEACTIVATED, PAUSED, and (API versions
    // 2025-09-24+) COMPLETED. There is no SUSPENDED status. See
    // isRecurStatusChangeAllowed() for when a mapped status is applied.
    switch ($squareStatus) {
      case 'PENDING':
      case 'PAUSED':
        return self::recurStatusId('Pending');

      case 'ACTIVE':
        return self::recurStatusId('In Progress');

      case 'COMPLETED':
        return self::recurStatusId('Completed');

      case 'CANCELED':
        return self::recurStatusId('Cancelled');

      case 'DEACTIVATED':
        return self::recurStatusId('Failed');
    }

    // If unknown, don't change local status.
    return NULL;
  }

  /**
   * Map Square payment statuses to CiviCRM contribution status IDs.
   *
   * @param string $squareStatus
   *   Status from Square API (e.g., 'COMPLETED', 'PENDING', 'FAILED', 'CANCELED').
   *
   * @return int|null
   *   CiviCRM contribution_status_id or NULL if unmapped.
   */
  public static function mapPaymentStatus($squareStatus) {
    $squareStatus = strtoupper(trim($squareStatus));

    switch ($squareStatus) {
      case 'COMPLETED':
        return self::contributionStatusId('Completed');

      // APPROVED: authorized, but the funds are not captured yet.
      case 'APPROVED':
      case 'PENDING':
      case 'PROCESSING':
        return self::contributionStatusId('Pending');

      case 'FAILED':
      case 'DECLINED':
      case 'CANCELED':
        return self::contributionStatusId('Failed');

      case 'REFUNDED':
        return self::contributionStatusId('Refunded');

      default:
        return NULL;
    }
  }

  /**
   * Map a Square source_type to a CiviCRM payment_instrument_id.
   *
   * Square source_type values: CARD, BANK_ACCOUNT, WALLET, CASH, EXTERNAL,
   * BUY_NOW_PAY_LATER, SQUARE_ACCOUNT.
   *
   * Returns CiviCRM's Credit Card, EFT or Cash payment instrument, resolved
   * by name.
   */
  public static function mapPaymentInstrument(?string $sourceType): int {
    switch (strtoupper((string) $sourceType)) {
      case 'CARD':
        // Apple Pay / Google Pay / Cash App Pay tokenize as cards.
      case 'WALLET':
      case 'BUY_NOW_PAY_LATER':
      case 'SQUARE_ACCOUNT':
        return self::paymentInstrumentId('Credit Card');

      case 'BANK_ACCOUNT':
        return self::paymentInstrumentId('EFT');

      case 'CASH':
        return self::paymentInstrumentId('Cash');

      default:
        // Credit Card (Square's most common instrument)
        return self::paymentInstrumentId('Credit Card');
    }
  }

}
