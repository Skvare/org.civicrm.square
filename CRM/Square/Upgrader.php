<?php

/**
 * Collection of upgrade steps for org.civicrm.square.
 *
 * Every install of org.civicrm.square started at revision 1002 or later
 * (earlier steps came before the extension was renamed), so steps start at
 * 1003.
 */
class CRM_Square_Upgrader extends CRM_Extension_Upgrader_Base {

  /**
   * Settings that held what square_catalog_map now does, by object type.
   */
  protected const LEGACY_CATALOG_SETTINGS = [
    'plan' => 'org_square_plan_cache',
    'plan_variation' => 'org_square_plan_variation_cache',
  ];

  /**
   * Runs on fresh installs only.
   */
  public function install(): void {
    _square_assert_php_version();
    $this->createSquareCustomerMapTable();
    $this->createSquareCatalogMapTable();
  }

  /**
   * Keep the Square plans and plan variations created so far in a table.
   *
   * They were kept in settings, which CiviCRM loads on every request, and
   * there is one per amount and cadence ever paid. Moved rather than
   * dropped: without them the next payment for each would create a
   * duplicate in the Square catalog.
   */
  public function upgrade_1003(): bool {
    _square_assert_php_version();
    $this->createSquareCatalogMapTable();
    foreach ($this->getLegacyCatalogObjects() as [$type, $key, $objectId]) {
      // The key starts with the payment processor ID (see
      // CRM_Square_Subscriptions).
      $processorId = (int) strtok($key, '_');
      if ($processorId > 0) {
        $this->insertCatalogObject($processorId, $type, $key, $objectId);
      }
    }
    $this->deleteLegacyCatalogSettings();
    return TRUE;
  }

  /**
   * Runs on uninstall. Drops the tables this extension owns.
   */
  public function uninstall(): void {
    CRM_Core_DAO::executeQuery('DROP TABLE IF EXISTS `square_catalog_map`');
    CRM_Core_DAO::executeQuery('DROP TABLE IF EXISTS `square_customer_map`');
  }

  /**
   * Create the square_customer_map table, if it doesn't already exist.
   *
   * Maps (contact_id, payment_processor_id) -> square_customer_id, and each
   * Square customer to at most one contact per payment processor.
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
        UNIQUE KEY `UI_processor_customer` (`payment_processor_id`, `square_customer_id`),
        KEY `IDX_square_customer_id` (`square_customer_id`),
        CONSTRAINT `FK_square_customer_map_contact_id` FOREIGN KEY (`contact_id`)
          REFERENCES `civicrm_contact` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT,
        CONSTRAINT `FK_square_customer_map_payment_processor_id` FOREIGN KEY (`payment_processor_id`)
          REFERENCES `civicrm_payment_processor` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
      SQL);
  }

  /**
   * Create the square_catalog_map table, if it doesn't already exist.
   *
   * Maps what a Square subscription plan or plan variation was created for
   * to its Square catalog object ID (see CRM_Square_Subscriptions).
   */
  protected function createSquareCatalogMapTable(): void {
    CRM_Core_DAO::executeQuery(<<<SQL
      CREATE TABLE IF NOT EXISTS `square_catalog_map` (
        `id` int unsigned NOT NULL AUTO_INCREMENT,
        `payment_processor_id` int unsigned NOT NULL,
        `object_type` varchar(32) NOT NULL COMMENT 'plan or plan_variation',
        `object_key` varchar(255) NOT NULL,
        `square_object_id` varchar(255) NOT NULL,
        `created_date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `UI_object` (`object_type`, `object_key`),
        CONSTRAINT `FK_square_catalog_map_payment_processor_id` FOREIGN KEY (`payment_processor_id`)
          REFERENCES `civicrm_payment_processor` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
      SQL);
  }

  /**
   * Catalog objects kept in the legacy settings, in every domain.
   *
   * @return array
   *   [object type, key, Square catalog object ID] triples.
   */
  protected function getLegacyCatalogObjects(): array {
    $dao = CRM_Core_DAO::executeQuery(
      'SELECT name, value FROM civicrm_setting WHERE name IN (%1, %2)',
      [
        1 => [self::LEGACY_CATALOG_SETTINGS['plan'], 'String'],
        2 => [self::LEGACY_CATALOG_SETTINGS['plan_variation'], 'String'],
      ]
    );
    $types = array_flip(self::LEGACY_CATALOG_SETTINGS);
    $objects = [];
    while ($dao->fetch()) {
      $cache = $dao->value === NULL ? NULL : unserialize($dao->value, ['allowed_classes' => FALSE]);
      foreach (is_array($cache) ? $cache : [] as $key => $objectId) {
        if (is_string($objectId) && $objectId !== '') {
          $objects[] = [$types[$dao->name], (string) $key, $objectId];
        }
      }
    }
    return $objects;
  }

  /**
   * Save a catalog object to square_catalog_map.
   *
   * @param int $processorId
   * @param string $type
   * @param string $key
   * @param string $objectId
   */
  protected function insertCatalogObject(int $processorId, string $type, string $key, string $objectId): void {
    // IGNORE: also skips one whose payment processor has been deleted.
    CRM_Core_DAO::executeQuery(
      'INSERT IGNORE INTO square_catalog_map (payment_processor_id, object_type, object_key, square_object_id) VALUES (%1, %2, %3, %4)',
      [
        1 => [$processorId, 'Integer'],
        2 => [$type, 'String'],
        3 => [$key, 'String'],
        4 => [$objectId, 'String'],
      ]
    );
  }

  /**
   * Delete the legacy settings, in every domain.
   */
  protected function deleteLegacyCatalogSettings(): void {
    CRM_Core_DAO::executeQuery(
      'DELETE FROM civicrm_setting WHERE name IN (%1, %2)',
      [
        1 => [self::LEGACY_CATALOG_SETTINGS['plan'], 'String'],
        2 => [self::LEGACY_CATALOG_SETTINGS['plan_variation'], 'String'],
      ]
    );
  }

}
