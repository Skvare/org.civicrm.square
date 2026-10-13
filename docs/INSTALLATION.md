# Installation and upgrades

## Requirements

- CiviCRM 6.10 or later.
- PHP 8.2 or later.
- Enabled `mjwshared` and `civi_contribute` CiviCRM extensions.
- Square sandbox credentials for initial testing.

Composer dependencies (runtime only) are committed in `vendor/`; a standard
extension install does not require `composer install`.

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
payment processor to a Square customer ID, and `square_catalog_map`, which
remembers the Square subscription plans and plan variations created for each
processor so that each is created only once. Upgrade step `1003` creates
`square_catalog_map` on an existing install and moves into it the plans and
variations recorded so far, which earlier versions kept in settings.

Cards are stored in CiviCRM core `PaymentToken` records, not in the extension
tables. Uninstalling removes `square_customer_map` and `square_catalog_map`,
but does not remove core payment-token or webhook data.