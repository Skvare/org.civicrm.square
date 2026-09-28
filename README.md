# org.uschess.square

Square payment processor extension for CiviCRM.

- **Extension key:** `org.uschess.square`
- **Version:** 1.2.0 (beta)
- **CiviCRM compatibility:** 6.16+
- **License:** AGPL-3.0-or-later
- **Square API version:** 2025-10-16 (sent by the bundled `square/square` 43.2 SDK)
- **Requires:** `mjwshared`, `civi_contribute` (see `info.xml`)

---

## Installation

This extension ships its `vendor/` directory (and `composer.lock`) committed to the
repository, so no `composer install` step is required after cloning/downloading —
just enable the extension as usual (**Administer → System Settings → Extensions**).

---

## Features

- One-time card payments via Square Payments API (`/v2/payments`)
- Recurring contributions via Square Subscriptions API (`/v2/subscriptions`)
- Refunds via Square Refunds API (`/v2/refunds`)
- Subscription cancellation and amount changes synced to Square
- Square Web Payments SDK for browser-side card tokenization — card details never pass through CiviCRM
- Card-on-file support through CiviCRM PaymentToken
- Square customer creation and deduplication (by email and `reference_id`)
- Webhook event handling with deduplication and delivery logging
- Supports sandbox (test) and production environments

---

## Payment Processor Configuration

Navigate to **Administer → System Settings → Payment Processors** and create a new processor of type **Square**.

| Field | Description |
|---|---|
| Square Application ID | Found under Developer Dashboard → Your Application → Credentials |
| Square Access Token | Live or sandbox access token |
| Square Location ID | Found under Locations in your Square Dashboard |
| Square Webhook Signature Key | Used to validate incoming webhook events (HMAC-SHA256) |

Separate sandbox credentials are supported for test mode. The processor automatically sets `billing_mode = 1` (on-site) on enable to ensure compatibility with Drupal Webform CiviCRM.

---

## Recurring Payments — Supported Cadences

| Cadence | Interval |
|---|---|
| DAILY | Every day |
| WEEKLY | Every week |
| EVERY_TWO_WEEKS | Every 2 weeks |
| MONTHLY | Every month |
| EVERY_TWO_MONTHS | Every 2 months |
| QUARTERLY | Every 3 months |
| EVERY_SIX_MONTHS | Every 6 months |
| ANNUAL | Every year |

Recurring payments use the Square Catalog API to create subscription plans and plan variations on demand, then create a Square Subscription linked to a card-on-file. The subscription starts immediately and Square itself charges every installment, including the first — no separate charge is made at checkout. The checkout's contribution stays Pending until Square confirms that first charge by webhook, which then completes that same contribution; each later installment becomes a new contribution (via `Contribution.repeattransaction`) completed the same way. All payments are recorded with CiviCRM's `Payment.create`, and the Square payment ID becomes the contribution's and the payment's transaction ID.

---

## Webhook Events

Configure the Square webhook endpoint as
`https://your-site.org/civicrm/payment/ipn/{processor_id}`. It validates the
`X-Square-Hmacsha256-Signature` header before the event is queued. The queue
record is processed immediately after it is accepted. A record that fails
transiently (Square unavailable, or a related CiviCRM record not saved yet
because webhooks arrived out of order) is left in status `new` for the
**Process Payment Processor Webhooks** scheduled job to retry, for up to 72
hours; any other failure is marked `error`. See
[docs/WEBHOOKS.md](docs/WEBHOOKS.md).

### Handled events

| Event | Action |
|---|---|
| `subscription.created` | Syncs subscription status to `ContributionRecur` |
| `subscription.updated` | Syncs subscription status/amount to `ContributionRecur` |
| `subscription.canceled` | Marks `ContributionRecur` as Cancelled |
| `invoice.created` | Nothing is recorded: the invoice is still a draft Square has not charged |
| `invoice.payment_made` | Records the installment: completes the checkout's Pending contribution for the first invoice, or creates and completes the next contribution of the series |
| `invoice.payment_failed` | Marks that installment's contribution Failed (the first installment's stays Pending, since Square keeps the invoice open) |
| `payment.updated` | Completes a one-time contribution; for a subscription payment, looks up its invoice at Square and records the installment exactly as `invoice.payment_made` does |
| `refund.created`, `refund.updated` | Records a completed refund as a negative payment against the refunded payment |

Webhook deduplication uses the `civicrm_paymentprocessor_webhook` queue (provided by the mjwshared extension)
and Square's globally unique event ID. Do not log webhook signatures, access
tokens, card nonces, or unredacted Square responses.

---

## Card & Customer Storage

Square identifiers are **not** stored as Contact custom fields. Both are
scoped per payment processor, since live and sandbox (or multiple Square
merchant accounts) are entirely separate environments with their own
customer/card records:

- **Cards** are stored as standard CiviCRM `PaymentToken` records
  (`civicrm_payment_token`), one per card, linked from
  `civicrm_contribution_recur.payment_token_id`. This lets a contact have
  more than one card on file across different recurring contributions,
  and lets other CiviCRM UI/tooling that understands `PaymentToken` work
  with them normally.
- **Square customer IDs** are stored in `square_customer_map`, an
  extension-owned table keyed by `(contact_id, payment_processor_id)` —
  see [Database Tables](#database-tables) below.

Earlier versions of this extension stored both as a `square_data` custom
field group on the Contact entity. `CRM_Square_Upgrader::upgrade_1000()`
migrates that legacy data into `square_customer_map` automatically **only**
when a contact's stored customer ID can be attributed to exactly one
configured Square processor. If more than one Square processor is
configured, the legacy field never recorded which one a given value
belongs to, so migration is skipped for that data (never guessed — live is
never assumed) and it's logged via `Civi::log()->warning()` for manual
reconciliation. The `square_data` group itself is only removed once
nothing ambiguous remains.

---

---

## JavaScript Integration

`js/square.js` provides full browser-side integration with the Square Web Payments SDK. It supports:

- CiviCRM native contribution pages and event registration forms
- Drupal Webform (webform_civicrm module) billing blocks, including AJAX reloads

Back-office (staff-entered) card payments are not supported.

Key globals:
- `CRM.squarePayment` — Square's namespaced integration state
- `CRM.vars.orgUschessSquare` — processor settings (Application ID, Location ID, sandbox flag)
- `window.civicrmSquareHandleReload` — reinitializes the card element when the billing block is replaced via AJAX

The card element mounts into `#square-card-container`. Tokenization happens on form submit; the resulting nonce is written to a hidden `square_payment_token` field for PHP to read.

---

---

## CiviCRM Hooks

| Hook | Purpose |
|---|---|
| `hook_civicrm_config` | Standard civix bootstrap |
| `hook_civicrm_install` | Standard civix install (schema/table creation is handled separately by `CRM_Square_Upgrader`, not this hook) |
| `hook_civicrm_uninstall` | Defensive cleanup of the legacy `square_data` custom field group, if it's still present |
| `hook_civicrm_enable` | Enforces `billing_mode = 1` on all Square payment processor instances |
| `hook_civicrm_managed` | Wires up `managed/PaymentProcessorType.mgd.php` (registers the Square payment processor type) |
| `hook_civicrm_navigationMenu` | Adds "Square Settings" under Administer → System Settings |

---

## Database Tables

| Table | Owner | Purpose |
|---|---|---|
| `square_customer_map` | This extension (`CRM_Square_Upgrader`) | Maps `(contact_id, payment_processor_id)` → Square customer ID; each customer maps to one contact per processor. Created on install; dropped on uninstall. |
| `civicrm_payment_token` | CiviCRM core | Stores Square card-on-file references (one row per card), linked from `civicrm_contribution_recur.payment_token_id`. Not owned by this extension — never dropped on uninstall. |
| `civicrm_paymentprocessor_webhook` | mjwshared extension | Webhook queue; retains deduplication, status and retry information. Not owned by this extension. |

---

## File Structure

```
CRM/
  Core/Payment/
    Square.php               Payment processor adapter (CiviCRM's CRM_Core_Payment contract)
    SquareIPN.php            Webhook event router and queue processing
    SquareRetryableException.php  Marks transient failures for webhook retry
    SquareDebugLogger.php    Opt-in verbose debug logging, gated by the square_ipn_debug_logging setting
  Square/
    Gateway.php              Square API access (client, errors, idempotency keys)
    Customers.php            Square customers and cards on file
    Subscriptions.php        Catalog plans/variations; subscription changes and cancellation
    Reconciler.php           Webhook-driven sync into CiviCRM's ledger
    Status.php               CiviCRM option values and Square status mappings
    Form/Settings.php        Administer > System Settings > Square Settings (debug logging toggle)
    Upgrader.php             Creates/backfills/drops the square_customer_map table (see Database Tables)
js/
  square.js                  Browser-side Square Web Payments SDK integration
managed/
  PaymentProcessorType.mgd.php  Registers the Square payment processor type
settings/
  Square.setting.php         Declares the square_ipn_debug_logging setting
templates/
  CRM/Core/Payment/Square/Card.tpl  Card container HTML injected into billing block
  CRM/Square/Form/Settings.tpl      Settings form markup
xml/Menu/square.xml          Route: civicrm/admin/setting/square -> CRM_Square_Form_Settings
square.php                   Extension bootstrap and hooks (config/install/uninstall/enable/managed/navigationMenu)
```

---

## Deployment checklist

Use sandbox credentials first. Before accepting live payments, confirm each of
the following:

1. Enable the required `mjwshared` and `civi_contribute` extensions, then
   enable this extension.
2. Create a Square payment processor in CiviCRM and enter the sandbox
   Application ID, Access Token, Location ID, and Webhook Signature Key.
3. In the Square Developer Dashboard, create a webhook subscription for that
   same sandbox application and set its notification URL to
   `https://your-site.org/civicrm/payment/ipn/{processor_id}`. Replace
   `{processor_id}` with the CiviCRM payment processor ID.
4. Subscribe to the events in [Webhook Events](#webhook-events). Events not
   listed there are acknowledged but intentionally ignored.
5. Verify that mjwshared's **Process Payment Processor Webhooks** scheduled job
   is enabled. It retries queued records left in status `new` by a transient
   failure.
6. Complete a test one-time contribution and, if recurring payments are in
   scope, a test recurring contribution. Confirm the contribution, payment
   token, recurring contribution, and webhook records in CiviCRM.
7. Repeat the configuration with production credentials and the production
   Square application only after sandbox verification succeeds.

The notification URL configured in Square must be the same URL CiviCRM uses
for the payment processor. Square's HMAC signature covers the full URL and raw
request body; a different scheme, hostname, path, or proxy rewrite causes
signature validation to fail.

## Operations and troubleshooting

The extension records webhook delivery and processing state in
`civicrm_paymentprocessor_webhook`. Review records with `status = error` and
the CiviCRM log when reconciliation does not occur.

| Symptom | Check |
|---|---|
| Webhook returns HTTP 401 | Verify the processor's Webhook Signature Key and the exact notification URL in Square. Do not trim or transform the raw request body. |
| Webhook returns HTTP 400 | Verify Square is posting valid JSON. |
| No change after a webhook | Confirm the event type is supported, the processor ID in the URL is correct, and the queue record is not in an error state. |
| Repeated webhook delivery | Look for a failed queue record and resolve its logged processing error; Square retries non-successful deliveries. |
| Card field does not appear | Confirm the selected processor is Square, on-site payment collection is enabled, and browser JavaScript is loading without Content Security Policy errors. |
| Sandbox data appears missing in live mode | Sandbox and production use different Square customers, cards, credentials, and processor configuration. |

Enable verbose webhook diagnostics at **Administer → System Settings → Square
Settings** only while investigating an issue. Disable it after the investigation
and never place access tokens, signature keys, card tokens, or unredacted API
responses in tickets or logs.

## Development

Requirements: PHP 8.2 or later and the dependencies committed in `vendor/`.
Run these checks from the extension root before submitting a change:

```bash
vendor/bin/phpcs --standard=phpcs.xml.dist CRM square.php settings managed tests
vendor/bin/phpunit --configuration tests/phpunit/phpunit.xml.dist
```

The codebase follows CiviCRM's two-space, Drupal-derived PHP style. The local
`.editorconfig` enforces whitespace basics, while `phpcs.xml.dist` records the
necessary CiviCRM exceptions for legacy `CRM_*` class names and extension hook
functions. Do not run automatic formatters across `vendor/`.
