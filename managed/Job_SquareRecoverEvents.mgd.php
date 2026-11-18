<?php

/**
 * @file
 * Scheduled job recovering Square events whose webhooks never arrived.
 *
 * Like every scheduled job, it does not run on a site whose environment is
 * not Production, so a copy of a live site cannot import live events.
 */

use CRM_Square_ExtensionUtil as E;

return [
  [
    'name' => 'SquareRecoverEvents',
    'entity' => 'Job',
    'cleanup' => 'always',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Square: recover missed webhook events',
        'description' => E::ts('Fetch recent Square events whose webhooks never reached CiviCRM (e.g. while the site was down, or a webhook was misconfigured), and process them. Requires Square event search, enabled from Administer > System Settings > Square Webhooks.'),
        'run_frequency' => 'Hourly',
        'api_entity' => 'Square',
        'api_action' => 'recoverevents',
        'parameters' => 'since=-3 days',
        'is_active' => TRUE,
      ],
      'match' => [
        'name',
      ],
    ],
  ],
];
