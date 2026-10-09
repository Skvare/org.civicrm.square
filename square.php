<?php

/**
 * @file
 * Extension bootstrap and CiviCRM hook implementations for org.civicrm.square.
 */

declare(strict_types=1);

// phpcs:disable PSR1.Files.SideEffects
require_once 'square.civix.php';
// phpcs:enable

use Civi\Api4\CustomGroup;
use Civi\Api4\CustomField;

/**
 * Minimum PHP version this extension requires.
 *
 * Composer.json's "php" constraint only governs `composer install`; it is
 * never checked at enable/upgrade time, so a site could still enable this
 * extension on an older, unsupported PHP — CiviCRM 6.16 itself still
 * supports PHP 8.1.
 */
const SQUARE_MIN_PHP_VERSION = '8.2.0';

/**
 * Abort enable/upgrade with a clear error if running on an unsupported PHP.
 *
 * @throws \CRM_Core_Exception
 */
function _square_assert_php_version(): void {
  if (version_compare(PHP_VERSION, SQUARE_MIN_PHP_VERSION, '<')) {
    throw new CRM_Core_Exception(
      \CRM_Square_ExtensionUtil::ts('The Square payment processor extension requires PHP %1 or newer; this site is running PHP %2.', [
        1 => SQUARE_MIN_PHP_VERSION,
        2 => PHP_VERSION,
      ])
    );
  }
}

/**
 * Implements hook_civicrm_config().
 */
function square_civicrm_config(\CRM_Core_Config $config): void {
  _square_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_install().
 */
function square_civicrm_install(): void {
  _square_assert_php_version();
  _square_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_uninstall().
 *
 * Removes the legacy square_data custom group (and its fields), if an
 * earlier version left one behind, so uninstalling the extension doesn't
 * leave orphaned schema. square_customer_map is dropped by
 * CRM_Square_Upgrader::uninstall().
 */
function square_civicrm_uninstall(): void {
  try {
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
  catch (CRM_Core_Exception $e) {
    Civi::log()->error('Square extension uninstall: failed to remove square_data custom group: ' . $e->getMessage());
  }
}

/**
 * Implements hook_civicrm_enable().
 */
function square_civicrm_enable(): void {
  _square_assert_php_version();
  _square_civix_civicrm_enable();
}

/**
 * Implements hook_civicrm_merge().
 *
 * Moves the merged-away contact's Square customer mappings to the contact
 * kept. Where both contacts have a customer for the same payment processor,
 * the kept contact's mapping wins (UPDATE IGNORE skips the row the unique
 * contact/processor key would reject) and the other is dropped. Registering
 * the table in 'cidRefs' instead would make core run a plain UPDATE, which
 * that key would make fail.
 */
function square_civicrm_merge($type, &$data, $mainId = NULL, $otherId = NULL, $tables = NULL): void {
  if ($type !== 'sqls' || empty($mainId) || empty($otherId)) {
    return;
  }
  $mainId = (int) $mainId;
  $otherId = (int) $otherId;
  $data[] = "UPDATE IGNORE square_customer_map SET contact_id = {$mainId} WHERE contact_id = {$otherId}";
  $data[] = "DELETE FROM square_customer_map WHERE contact_id = {$otherId}";
}

/**
 * Implements hook_civicrm_navigationMenu().
 *
 * Adds "Square Settings" under Administer > System Settings.
 */
function square_civicrm_navigationMenu(&$menu): void {
  _square_civix_insert_navigation_menu($menu, 'Administer/System Settings', [
    'label' => \CRM_Square_ExtensionUtil::ts('Square Settings'),
    'name' => 'square_settings',
    'url' => 'civicrm/admin/setting/square?reset=1',
    'permission' => 'administer CiviCRM',
    'operator' => 'OR',
    'separator' => 0,
  ]);
  _square_civix_navigationMenu($menu);
}
