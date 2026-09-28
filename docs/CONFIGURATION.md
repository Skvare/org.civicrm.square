# Payment processor configuration

Create a processor of type **Square** at **Administer → System Settings →
Payment Processors**.

| CiviCRM field | Square value | Use |
|---|---|---|
| Square Application ID | Application credential | Loads the browser Web Payments SDK. |
| Square Access Token | Application access token | Authorizes server-side Square calls. |
| Square Location ID | Merchant location ID | Identifies the payment location. |
| Square Webhook Signature Key | Webhook subscription key | Validates incoming notifications. |

Enter sandbox values in the test fields and production values in the live
fields. Customer IDs, card tokens, access tokens, and signature keys cannot be
shared between sandbox and production.

## Go-live process

1. Put the processor in test mode and configure sandbox credentials.
2. Configure a matching sandbox webhook subscription.
3. Complete a test one-time payment and, when used, a recurring payment.
4. Confirm the CiviCRM contribution, payment token, recurring contribution,
   and webhook records.
5. Repeat the setup with the production Square application and live
   credentials only after the sandbox flow succeeds.

Recurring contributions create or reuse Square Catalog plans and variations.
Square charges every installment, including the first, and reports it by
webhook: the checkout's contribution stays Pending until Square's first charge
is confirmed, so the webhook subscription (see [WEBHOOKS.md](WEBHOOKS.md)) must
be in place before recurring payments are taken. See
[WEBHOOKS.md](WEBHOOKS.md#recurring-installments) for how installments are
recorded and receipted.
