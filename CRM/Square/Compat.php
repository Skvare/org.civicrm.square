<?php

/**
 * Calls into CiviCRM core that differ between the versions this extension supports.
 *
 * The extension supports CiviCRM 6.10 and later (see info.xml). Where a
 * core function has changed since 6.10, check the running version with
 * isCiviVersionAtLeast() and call the function the way that version expects.
 */
class CRM_Square_Compat {

  /**
   * Whether the running CiviCRM is the given version or later.
   *
   * @param string $version
   *   E.g. "6.15".
   *
   * @return bool
   */
  public static function isCiviVersionAtLeast(string $version): bool {
    return version_compare(CRM_Utils_System::version(), $version, '>=');
  }

  /**
   * The line items of a recurring contribution's template contribution.
   *
   * That is the contribution CiviCRM copies later installments from (see
   * CRM_Contribute_BAO_ContributionRecur::getTemplateContribution()).
   *
   * @param int $recurId
   *
   * @return array
   *   One entry per line item.
   *
   * @throws \CRM_Core_Exception
   */
  public static function getTemplateLineItems(int $recurId): array {
    // CiviCRM 6.15 added getTemplateContribution()'s $isFlattenLineItems.
    // Earlier versions have no third parameter, and key line_item by price
    // set ID.
    if (self::isCiviVersionAtLeast('6.15')) {
      $template = CRM_Contribute_BAO_ContributionRecur::getTemplateContribution($recurId, [], TRUE);
      return $template['line_item'] ?? [];
    }
    $template = CRM_Contribute_BAO_ContributionRecur::getTemplateContribution($recurId);
    return self::flattenLineItems($template['line_item'] ?? []);
  }

  /**
   * Line items keyed by price set ID, a list under each, as one list.
   *
   * @param array $lineItemsByPriceSet
   *
   * @return array
   */
  public static function flattenLineItems(array $lineItemsByPriceSet): array {
    return array_merge(...array_values($lineItemsByPriceSet));
  }

}
