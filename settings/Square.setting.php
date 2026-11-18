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
  'square_min_amount' => [
    'name' => 'square_min_amount',
    'type' => 'Float',
    'html_type' => 'text',
    'default' => 0,
    'add' => '2.0.0',
    'is_domain' => 1,
    'is_contact' => 0,
    'title' => \CRM_Square_ExtensionUtil::ts('Minimum Card Payment Amount'),
    'description' => \CRM_Square_ExtensionUtil::ts('Card payments (and recurring installments) below this amount are refused on public forms before Square is asked to charge the card. Card testers usually try very small amounts, so if you never take payments under, say, 5.00, enter 5 here. 0 means no minimum. Staff on back-office forms are not limited.'),
    'help_text' => NULL,
    'html_attributes' => ['size' => 10, 'maxlength' => 10],
    'settings_pages' => ['square' => ['weight' => 30]],
  ],
  'square_statement_description' => [
    'name' => 'square_statement_description',
    'type' => 'String',
    'html_type' => 'text',
    'default' => '',
    'add' => '2.0.0',
    'is_domain' => 1,
    'is_contact' => 0,
    'title' => \CRM_Square_ExtensionUtil::ts('Card Statement Description'),
    'description' => \CRM_Square_ExtensionUtil::ts('Up to 20 characters that Square adds to the payer\'s card statement for one-time payments, after your business name (for example "DONATION"). Leave empty to use only what is set in your Square account. Square does not add it to subscription installments.'),
    'help_text' => NULL,
    'html_attributes' => ['size' => 20, 'maxlength' => 20],
    'settings_pages' => ['square' => ['weight' => 40]],
  ],
  // Internal: set by CRM_Square_EventRecovery::enableSearch(), not on a form.
  'square_event_search_enabled' => [
    'name' => 'square_event_search_enabled',
    'type' => 'Array',
    'default' => [],
    'add' => '2.0.0',
    'is_domain' => 1,
    'is_contact' => 0,
    'title' => \CRM_Square_ExtensionUtil::ts('Square Event Search Enabled'),
    'description' => \CRM_Square_ExtensionUtil::ts('When Square event search was enabled for each payment processor, by payment processor ID. Events that happened before then cannot be recovered.'),
    'help_text' => NULL,
  ],
];
