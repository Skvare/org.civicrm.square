<?php

/**
 * @file
 * Extension bootstrap and CiviCRM hook implementations for org.civicrm.square.
 */

declare(strict_types=1);

// phpcs:disable PSR1.Files.SideEffects
require_once 'square.civix.php';
// phpcs:enable

/**
 * Minimum PHP version this extension requires.
 *
 * Composer.json's "php" constraint only governs `composer install`; it is
 * never checked at enable/upgrade time, so a site could still enable this
 * extension on an older, unsupported PHP — CiviCRM itself still supports
 * PHP 8.1.
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
  // The Square SDK and its dependencies. Appended to the autoloaders, not
  // prepended (see composer.json), so the site's own copies of shared
  // libraries such as Symfony HttpClient and the PSR HTTP interfaces win.
  require_once __DIR__ . '/vendor/autoload.php';
}

/**
 * Implements hook_civicrm_install().
 */
function square_civicrm_install(): void {
  _square_assert_php_version();
  _square_civix_civicrm_install();
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
 * Implements hook_civicrm_post().
 *
 * Queues a contact whose name or email changed, so its Square customers are
 * updated at the end of the request (see CRM_Square_CustomerSync). Contacts
 * with no Square customer cost one query, once per request.
 */
function square_civicrm_post($op, $objectName, $objectId, &$objectRef): void {
  try {
    $isContact = in_array($objectName, ['Individual', 'Organization', 'Household', 'Contact'], TRUE);
    if ($isContact && in_array($op, ['edit', 'merge'], TRUE)) {
      CRM_Square_CustomerSync::queue((int) $objectId);
    }
    elseif ($objectName === 'Email' && in_array($op, ['create', 'edit'], TRUE)) {
      $contactId = is_object($objectRef) ? ($objectRef->contact_id ?? NULL) : NULL;
      if (empty($contactId) && $objectId) {
        $contactId = CRM_Core_DAO::getFieldValue('CRM_Core_DAO_Email', $objectId, 'contact_id');
      }
      CRM_Square_CustomerSync::queue((int) $contactId);
    }
  }
  catch (\Throwable $e) {
    // Never let the Square customer sync break a contact edit.
    \Civi::log('square')->warning('Square: could not queue a Square customer update: ' . $e->getMessage());
  }
}

/**
 * Implements hook_civicrm_check().
 *
 * @see CRM_Square_Check
 */
function square_civicrm_check(&$messages, $statusNames = [], $includeDisabled = FALSE): void {
  try {
    $messages = array_merge($messages, (new CRM_Square_Check())->run());
  }
  catch (\Throwable $e) {
    \Civi::log('square')->error('Square: system check failed: ' . $e->getMessage());
  }
}

/**
 * Implements hook_civicrm_navigationMenu().
 *
 * Adds "Square Settings", "Square Webhooks" and "Square Reconciliation"
 * under Administer > System Settings.
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
  _square_civix_insert_navigation_menu($menu, 'Administer/System Settings', [
    'label' => \CRM_Square_ExtensionUtil::ts('Square Webhooks'),
    'name' => 'square_webhooks',
    'url' => 'civicrm/admin/square/webhook?reset=1',
    'permission' => 'administer CiviCRM',
    'operator' => 'OR',
    'separator' => 0,
  ]);
  _square_civix_insert_navigation_menu($menu, 'Administer/System Settings', [
    'label' => \CRM_Square_ExtensionUtil::ts('Square Reconciliation'),
    'name' => 'square_reconcile',
    'url' => 'civicrm/admin/square/reconcile?reset=1',
    'permission' => 'administer CiviCRM',
    'operator' => 'OR',
    'separator' => 0,
  ]);
  _square_civix_navigationMenu($menu);
}
