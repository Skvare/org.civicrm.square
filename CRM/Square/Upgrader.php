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
    $this->createSquareCustomerMapTable(TRUE);
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
    // Without the unique customer key: upgrade_1001 adds it once it has
    // checked the backfilled mappings for conflicts.
    $this->createSquareCustomerMapTable(FALSE);
    $this->backfillSquareCustomerMapFromCustomField();
    return TRUE;
  }

  /**
   * Map each Square customer to at most one contact per payment processor.
   *
   * Adds a unique (payment_processor_id, square_customer_id) key, so that
   * one Square customer (and its cards) can never be shared by two CiviCRM
   * contacts. Existing mappings that already break that rule are never
   * resolved automatically — which contact a customer belongs to is a
   * decision for a person — so the upgrade stops and lists them.
   *
   * @throws \CRM_Core_Exception
   *   If existing mappings conflict.
   */
  public function upgrade_1001(): bool {
    _square_assert_php_version();
    if ($this->hasUniqueCustomerKey()) {
      return TRUE;
    }
    $conflicts = $this->findConflictingCustomerMappings();
    if ($conflicts) {
      throw new CRM_Core_Exception(self::describeCustomerMappingConflicts($conflicts));
    }
    $this->addUniqueCustomerKey();
    return TRUE;
  }

  /**
   * Let a Square payment processor be deleted.
   *
   * The foreign key from square_customer_map to civicrm_payment_processor
   * was ON DELETE RESTRICT, so deleting a processor that had mapped any
   * customer failed. A processor's mappings mean nothing without it, so they
   * now go with it.
   */
  public function upgrade_1002(): bool {
    _square_assert_php_version();
    $this->cascadeProcessorDeletes();
    return TRUE;
  }

  /**
   * Re-create the payment processor foreign key with ON DELETE CASCADE.
   */
  protected function cascadeProcessorDeletes(): void {
    CRM_Core_BAO_SchemaHandler::safeRemoveFK('square_customer_map', 'FK_square_customer_map_payment_processor_id');
    CRM_Core_DAO::executeQuery(
      'ALTER TABLE `square_customer_map` ADD CONSTRAINT `FK_square_customer_map_payment_processor_id`
       FOREIGN KEY (`payment_processor_id`) REFERENCES `civicrm_payment_processor` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT'
    );
  }

  /**
   * Explain which customer mappings block upgrade_1001, and how to fix them.
   *
   * @param array $conflicts
   *   Rows with payment_processor_id, square_customer_id and contact_ids
   *   (comma-separated).
   *
   * @return string
   */
  public static function describeCustomerMappingConflicts(array $conflicts): string {
    $lines = [];
    foreach ($conflicts as $conflict) {
      $lines[] = sprintf(
        'payment processor %d, Square customer %s: contacts %s',
        $conflict['payment_processor_id'],
        $conflict['square_customer_id'],
        str_replace(',', ', ', (string) $conflict['contact_ids'])
      );
    }
    return 'Square extension upgrade stopped: ' . count($conflicts) . ' Square customer(s) are mapped to more than one '
      . 'CiviCRM contact on the same payment processor, so each customer cannot be limited to one contact. For each, '
      . 'decide which contact the Square customer belongs to (e.g. by its email or reference ID in the Square '
      . 'Dashboard), delete the other contacts\' rows from square_customer_map, then run the upgrade again. '
      . implode('; ', $lines) . '.';
  }

  /**
   * Whether square_customer_map already has its unique customer key.
   */
  protected function hasUniqueCustomerKey(): bool {
    return CRM_Core_BAO_SchemaHandler::checkIfIndexExists('square_customer_map', 'UI_processor_customer');
  }

  /**
   * Square customers mapped to more than one contact on the same processor.
   *
   * @return array
   *   Rows with payment_processor_id, square_customer_id and contact_ids.
   */
  protected function findConflictingCustomerMappings(): array {
    $dao = CRM_Core_DAO::executeQuery(
      'SELECT payment_processor_id, square_customer_id, GROUP_CONCAT(contact_id ORDER BY contact_id) AS contact_ids
       FROM square_customer_map
       GROUP BY payment_processor_id, square_customer_id
       HAVING COUNT(*) > 1
       ORDER BY payment_processor_id, square_customer_id'
    );
    $conflicts = [];
    while ($dao->fetch()) {
      $conflicts[] = [
        'payment_processor_id' => (int) $dao->payment_processor_id,
        'square_customer_id' => $dao->square_customer_id,
        'contact_ids' => $dao->contact_ids,
      ];
    }
    return $conflicts;
  }

  /**
   * Add the unique (payment_processor_id, square_customer_id) key.
   */
  protected function addUniqueCustomerKey(): void {
    CRM_Core_DAO::executeQuery(
      'ALTER TABLE `square_customer_map` ADD UNIQUE KEY `UI_processor_customer` (`payment_processor_id`, `square_customer_id`)'
    );
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
   *
   * @param bool $uniqueCustomer
   *   Whether to include the unique (payment_processor_id,
   *   square_customer_id) key (see upgrade_1001()).
   */
  protected function createSquareCustomerMapTable(bool $uniqueCustomer): void {
    $uniqueCustomerKey = $uniqueCustomer
      ? 'UNIQUE KEY `UI_processor_customer` (`payment_processor_id`, `square_customer_id`),'
      : '';
    CRM_Core_DAO::executeQuery(<<<SQL
      CREATE TABLE IF NOT EXISTS `square_customer_map` (
        `id` int unsigned NOT NULL AUTO_INCREMENT,
        `contact_id` int unsigned NOT NULL,
        `payment_processor_id` int unsigned NOT NULL,
        `square_customer_id` varchar(255) NOT NULL,
        `created_date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `UI_contact_processor` (`contact_id`, `payment_processor_id`),
        {$uniqueCustomerKey}
        KEY `IDX_square_customer_id` (`square_customer_id`),
        CONSTRAINT `FK_square_customer_map_contact_id` FOREIGN KEY (`contact_id`)
          REFERENCES `civicrm_contact` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT,
        CONSTRAINT `FK_square_customer_map_payment_processor_id` FOREIGN KEY (`payment_processor_id`)
          REFERENCES `civicrm_payment_processor` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT
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
   * The legacy custom field never recorded which processor (or which of
   * several Square merchant accounts) a value came from. Values are
   * attributed to the live processor when exactly one live Square
   * processor is configured: sandbox processors are not considered, since
   * every configured processor has one. If two or more live Square
   * processors are configured, a legacy value's owning processor is
   * genuinely unknown and is not guessed. Those contacts are logged for
   * manual reconciliation and their legacy custom-field data is left in
   * place untouched.
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

    // Live processors only (API4's default, made explicit): each configured
    // processor also has a sandbox row, which would otherwise make every
    // site look ambiguous.
    $squareProcessorIds = [];
    foreach (PaymentProcessor::get(FALSE)
      ->addWhere('payment_processor_type_id:name', '=', 'Square')
      ->addWhere('is_test', '=', FALSE)
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
