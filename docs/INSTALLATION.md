# Installation and upgrades

## Requirements

- CiviCRM 6.16 or later.
- PHP 8.2 or later.
- Enabled `mjwshared` and `civi_contribute` CiviCRM extensions.
- Square sandbox credentials for initial testing.

Composer dependencies are committed in `vendor/`; a standard extension install
does not require `composer install`.

## Install

1. Place the extension in CiviCRM's extension directory using the key
   `org.civicrm.square`.
2. Enable **Square Payment Processor** at **Administer → System Settings →
   Extensions**.
3. Create a **Square** payment processor at **Administer → System Settings →
   Payment Processors**.
4. Complete [Configuration](CONFIGURATION.md), then configure
   [Webhooks](WEBHOOKS.md).

The managed entity registers the processor type. Square processors use on-site
card entry (`billing_mode = 1`).

## Data and upgrades

New installs create `square_customer_map`, which maps a CiviCRM contact and a
payment processor to a Square customer ID. Upgrade step `1000` migrates legacy
`square_data.square_customer_id` records only where exactly one Square
processor exists; values that cannot safely be attributed to an environment
remain in place and are logged for manual review.

Cards are stored in CiviCRM core `PaymentToken` records, not in the extension
table. Uninstalling removes `square_customer_map` and attempts cleanup of the
legacy custom fields, but does not remove core payment-token or webhook data.