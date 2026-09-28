<?php

use Civi\Api4\CustomField;
use Civi\Api4\CustomGroup;
use Civi\Api4\PaymentProcessor;

/**
 * Collection of upgrade steps for org.uschess.square.
 */
class CRM_Square_Upgrader extends CRM_Extension_Upgrader_Base {

  /**
   * Runs on fresh installs only.
   */
  public function install(): void {
    _square_assert_php_version();
    $this->createSquareCustomerMapTable();
  }

  /**
   * Runs the next time an already-installed copy of this extension checks for upgrades.
   *
   * Creates square_customer_map for sites that had the extension installed
   * before this table existed, backfills it from the legacy
   * square_data.square_customer_id custom field, then removes that
   * now-superseded custom field group.
   */
  public function upgrade_1000(): bool {
    _square_assert_php_version();
    $this->createSquareCustomerMapTable();
    $this->backfillSquareCustomerMapFromCustomField();
    return TRUE;
  }

  /**
   * Runs on uninstall. Drops the table this extension owns.
   */
  public function uninstall(): void {
    CRM_Core_DAO::executeQuery('DROP TABLE IF EXISTS `square_customer_map`');
  }

  /**
   * Create the square_customer_map table, if it doesn't already exist.
   *
   * Maps (contact_id, payment_processor_id) -> square_customer_id.
   */
  protected function createSquareCustomerMapTable(): void {
    CRM_Core_DAO::executeQuery(<<<SQL
      CREATE TABLE IF NOT EXISTS `square_customer_map` (
        `id` int unsigned NOT NULL AUTO_INCREMENT,
        `contact_id` int unsigned NOT NULL,
        `payment_processor_id` int unsigned NOT NULL,
        `square_customer_id` varchar(255) NOT NULL,
        `created_date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `UI_contact_processor` (`contact_id`, `payment_processor_id`),
        KEY `IDX_square_customer_id` (`square_customer_id`),
        CONSTRAINT `FK_square_customer_map_contact_id` FOREIGN KEY (`contact_id`)
          REFERENCES `civicrm_contact` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT,
        CONSTRAINT `FK_square_customer_map_payment_processor_id` FOREIGN KEY (`payment_processor_id`)
          REFERENCES `civicrm_payment_processor` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
      SQL);
  }

  /**
   * One-time migration of legacy square_data.square_customer_id values.
   *
   * Copies existing values into square_customer_map, but ONLY for contacts
   * whose value can unambiguously be attributed to a single Square payment
   * processor.
   *
   * The legacy custom field never recorded which processor (live vs.
   * sandbox, or which of several Square merchant accounts) a value came
   * from. If exactly one Square processor is configured on this site, every
   * legacy value can only ever have belonged to it — no inference is
   * needed because there is nothing else it could be. If two or more
   * Square processors are configured, a legacy value's owning processor is
   * genuinely unknown; we do not guess (in particular, we never assume
   * "live"). Those contacts are logged for manual reconciliation and their
   * legacy custom-field data is left in place untouched.
   *
   * The square_data custom field group is only removed once every legacy
   * value has been migrated — i.e. once there is no ambiguous data left
   * that still depends on it.
   */
  protected function backfillSquareCustomerMapFromCustomField(): void {
    $field = CustomField::get(FALSE)
      ->addWhere('custom_group_id:name', '=', 'square_data')
      ->addWhere('name', '=', 'square_customer_id')
      ->addSelect('id', 'column_name', 'custom_group_id.table_name')
      ->execute()
      ->first();

    if (empty($field)) {
      // Nothing to migrate (fresh install, or already migrated).
      return;
    }

    $table = $field['custom_group_id.table_name'];
    $column = $field['column_name'];

    $squareProcessorIds = [];
    foreach (PaymentProcessor::get(FALSE)
      ->addWhere('payment_processor_type_id:name', '=', 'Square')
      ->addSelect('id')
      ->execute() as $processor) {
      $squareProcessorIds[] = (int) $processor['id'];
    }

    if (empty($squareProcessorIds)) {
      // No Square processor configured at all — nothing to migrate to, and
      // nothing ambiguous to report either.
      return;
    }

    $legacyRows = CRM_Core_DAO::executeQuery(
      "SELECT entity_id AS contact_id, `{$column}` AS square_customer_id
       FROM `{$table}`
       WHERE `{$column}` IS NOT NULL AND `{$column}` != ''"
    );

    $migratedCount = 0;
    $ambiguous = [];

    while ($legacyRows->fetch()) {
      $contactId = (int) $legacyRows->contact_id;
      $customerId = $legacyRows->square_customer_id;

      if (count($squareProcessorIds) === 1) {
        // Unambiguous: only one Square processor exists on this site, so
        // the legacy value can only belong to it.
        CRM_Core_DAO::executeQuery(
          'INSERT IGNORE INTO square_customer_map (contact_id, payment_processor_id, square_customer_id)
           VALUES (%1, %2, %3)',
          [
            1 => [$contactId, 'Integer'],
            2 => [$squareProcessorIds[0], 'Integer'],
            3 => [$customerId, 'String'],
          ]
        );
        $migratedCount++;
      }
      else {
        // Ambiguous: multiple Square processors exist and the legacy field
        // does not say which one this value belongs to. Do not guess.
        $ambiguous[] = "contact_id={$contactId} square_customer_id={$customerId}";
      }
    }

    if ($migratedCount > 0) {
      Civi::log()->info(
        "Square extension upgrade: migrated {$migratedCount} legacy square_data.square_customer_id "
        . 'value(s) into square_customer_map.'
      );
    }

    if (!empty($ambiguous)) {
      Civi::log()->warning(
        'Square extension upgrade: ' . count($ambiguous) . ' legacy square_data.square_customer_id value(s) '
        . 'could NOT be automatically migrated because more than one Square payment processor ('
        . implode(', ', $squareProcessorIds) . ') is configured on this site and the legacy field does not '
        . 'record which processor each value belongs to. These require manual reconciliation — for each, '
        . 'determine (e.g. by checking the Square dashboard/API for each processor) which processor the '
        . 'customer ID actually belongs to and insert the correct row into square_customer_map directly. '
        . 'The legacy square_data custom field has been left in place until this is resolved. Records: '
        . implode('; ', $ambiguous)
      );
      // Leave the legacy custom field group in place — it's the only
      // record of the ambiguous mappings until they're manually resolved.
      return;
    }

    // Every legacy value has been migrated (or there were none) — the
    // square_data group is now fully superseded by square_customer_map.
    $group = CustomGroup::get(FALSE)
      ->addWhere('name', '=', 'square_data')
      ->addSelect('id')
      ->execute()
      ->first();
    if (!empty($group['id'])) {
      CustomField::delete(FALSE)
        ->addWhere('custom_group_id', '=', $group['id'])
        ->execute();
      CustomGroup::delete(FALSE)
        ->addWhere('id', '=', $group['id'])
        ->execute();
    }
  }

}
