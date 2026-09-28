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

Staff (and donors, on self-service links) can change a recurring
contribution's amount from CiviCRM; the change is sent to the Square
subscription, applying from its next installment. Changing the number of
installments is refused, since Square cannot change it on an existing
subscription, as is changing the amount of a recurring contribution with
more than one line item.

Upgrading to this version adds a unique key so that each Square customer maps
to one CiviCRM contact per payment processor. If existing mappings already
share a customer between contacts, the upgrade stops and lists them: decide
which contact each customer belongs to, delete the other rows from
`square_customer_map`, and run the upgrade again.
