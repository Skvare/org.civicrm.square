<?php

/**
 * @file
 * Settings metadata for the Square payment processor extension.
 */

return [
  'square_ipn_debug_logging' => [
    'name' => 'square_ipn_debug_logging',
    'type' => 'Boolean',
    'html_type' => 'checkbox',
    'quick_form_type' => 'YesNo',
    'default' => FALSE,
    'add' => '1.0.3',
    'is_domain' => 1,
    'is_contact' => 0,
    'title' => \CRM_Square_ExtensionUtil::ts('Square IPN Debug Logging'),
    'description' => \CRM_Square_ExtensionUtil::ts('When enabled, verbose Square webhook processing details (event dispatch, record lookups, created/updated records) are written to the CiviCRM debug log (ConfigAndLog). Leave disabled in normal operation.'),
    'help_text' => NULL,
    'settings_pages' => ['square' => ['weight' => 10]],
  ],
  'square_import_external_payments' => [
    'name' => 'square_import_external_payments',
    'type' => 'Boolean',
    'html_type' => 'checkbox',
    'quick_form_type' => 'YesNo',
    'default' => FALSE,
    'add' => '1.2.0',
    'is_domain' => 1,
    'is_contact' => 0,
    'title' => \CRM_Square_ExtensionUtil::ts('Import Square Payments Made Outside CiviCRM'),
    'description' => \CRM_Square_ExtensionUtil::ts('When enabled, a completed Square payment that matches no CiviCRM contribution or subscription (for example a Square Dashboard or Square Online sale) is recorded as a new Donation contribution, if it was taken at the payment processor\'s Square location and its contact can be identified. Payments at other locations, including point of sale, are always ignored.'),
    'help_text' => NULL,
    'settings_pages' => ['square' => ['weight' => 20]],
  ],
];
