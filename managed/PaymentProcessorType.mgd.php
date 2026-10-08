<?php

/**
 * @file
 * Managed payment processor type metadata.
 */

return [
  [
    'name'   => 'SquarePaymentProcessor',
    'entity' => 'payment_processor_type',
    'module' => 'org.uschess.square',
    'update' => 'always',
    'cleanup' => 'never',
    'params' => [
      'version'     => 3,
      'title'       => 'Square',
      'name'        => 'Square',
      'description' => 'Square payment processor for US Chess',

      // Public payment-processor class in CRM/Core/Payment/Square.php.
      'class_name'  => 'Payment_Square',

      // Admin form labels, for the live and the test credentials alike
      // (payment_processor_type has no separate test labels).
      'user_name_label' => 'Square Application ID',
      'password_label'  => 'Square Access Token',
      'signature_label' => 'Square Location ID',
      'subject_label'   => 'Square Webhook Signature Key',

      // Base URLs – we mostly use the SDK, but Civi still likes these sane defaults.
      // LIVE.
      'url_site_default' => 'https://connect.squareup.com',
      'url_api_default'  => 'https://connect.squareup.com',

      // TEST (sandbox)
      'url_site_test_default' => 'https://connect.squareupsandbox.com',
      'url_api_test_default'  => 'https://connect.squareupsandbox.com',

      // On-site card entry (we use Web Payments SDK)
      // 1 = onsite, 4 = offsite/redirect.
      'billing_mode' => 1,

      // 1 = credit card
      'payment_type' => 1,

      // Supports recurring contributions (see also
      // CRM_Core_Payment_Square::supportsRecurring()).
      'is_recur'        => 1,
    ],
  ],
];
