# Architecture

## Components

| Component | Responsibility |
|---|---|
| `CRM/Core/Payment/Square.php` | Payment processor: browser assets, payments, refunds, subscriptions, and notification entry point. |
| `CRM/Core/Payment/SquareIPN.php` | Webhook filtering, deduplication, queue persistence, and processing. |
| `CRM/Core/Payment/SquareDebugLogger.php` | Opt-in diagnostic logging. |
| `CRM/Square/Upgrader.php` | Schema creation, migration, and cleanup. |
| `js/square.js` | Browser card element and tokenization. |
| `managed/PaymentProcessorType.mgd.php` | Payment processor type registration. |

## Payment flow

1. The processor injects Square SDK configuration and the card container into
   CiviCRM's billing block.
2. The browser tokenizes card data and adds the payment token to the form.
3. The processor sends the token to Square for payment or card-on-file work.
4. The processor returns transaction and status data to CiviCRM.

Raw card details do not pass through CiviCRM. Recurring contributions create or
reuse Square Catalog plans and variations, then link the Square subscription to
the CiviCRM recurring contribution.

## Data ownership

| Data | Storage | Owner |
|---|---|---|
| Square customer mapping | `square_customer_map` | This extension |
| Card-on-file reference | `civicrm_payment_token` | CiviCRM core |
| Recurring contribution | `civicrm_contribution_recur` | CiviCRM core |
| Webhook state | `civicrm_paymentprocessor_webhook` | mjwshared extension |

Customer mappings use `(contact_id, payment_processor_id)`, separating
sandbox, production, and different Square merchant accounts.
