# Architecture

## Components

| Component | Responsibility |
|---|---|
| `CRM/Core/Payment/Square.php` | CiviCRM's payment-processor adapter (`CRM_Core_Payment`): billing block, checkout payments, refunds, recurring setup, amount changes, cancellation, and the webhook entry point. Delegates to the classes below. |
| `CRM/Square/Gateway.php` | Square API access for one processor: SDK client, credentials and environment, idempotency keys, and translation of SDK errors (transient ones as `CRM_Core_Payment_SquareRetryableException`). |
| `CRM/Square/Customers.php` | Square customers and cards on file: `square_customer_map` and PaymentToken records. |
| `CRM/Square/Subscriptions.php` | Catalog subscription plans and plan variations (cached per processor), and changes to or cancellation of subscriptions. |
| `CRM/Square/Reconciler.php` | Webhook-driven sync into CiviCRM: payments, subscription installments, refunds, and recurring status. Its protected find/record methods are its only CiviCRM data access. |
| `CRM/Square/Status.php` | CiviCRM option values by name, and Square-to-CiviCRM status mappings. |
| `CRM/Core/Payment/SquareIPN.php` | Webhook filtering, deduplication, queue persistence, and processing. |
| `CRM/Core/Payment/SquareDebugLogger.php` | Opt-in diagnostic logging. |
| `CRM/Square/Upgrader.php` | Schema creation, migration, and cleanup. |
| `js/square.js` | Browser card element and tokenization; keeps the card form's postal code in step with the billing address's. |
| `managed/PaymentProcessorType.mgd.php` | Payment processor type registration. |

## Payment flow

1. The processor injects Square SDK configuration and the card container into
   CiviCRM's billing block.
2. The browser checks that the card form's postal code matches the billing
   address's, tokenizes card data, with the buyer's details for Square's
   buyer verification, and adds the payment token to the form. (Square's
   CreateCard rejects a card whose billing address postal code differs from
   the card form's, with "Invalid card data.")
3. The processor sends the token to Square for payment or card-on-file work.
4. The processor returns transaction and status data to CiviCRM.

Failures are reported as CiviCRM's checkout expects:

- When nothing was charged — a decline, a request Square rejected, or a
  failure before the charge — `doPayment()` throws
  `PaymentProcessorException`, and the checkout marks the contribution Failed
  and deletes the recurring contribution.
- When Square may have charged the card without confirming it (a server
  error or timeout after the SDK's retries, an unreadable response), it
  throws `CRM_Core_Payment_SquareOutcomeUnknownException` and logs an error to
  check in the Square Dashboard. A contribution page keeps its Pending
  contribution, for the `payment.updated` webhook to complete if the charge
  went through. Event registration only handles `PaymentProcessorException`,
  so there it is converted to one.

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
sandbox, production, and different Square merchant accounts. Each Square
customer maps to at most one contact per processor (`UI_processor_customer`,
added by upgrade 1001, which stops and lists any existing conflicts rather
than resolving them).
