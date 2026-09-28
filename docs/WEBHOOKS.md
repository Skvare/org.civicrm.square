# Webhooks and operations

## Configure Square

For each CiviCRM Square processor, configure a Square webhook subscription:

```
https://your-site.example/civicrm/payment/ipn/{processor_id}
```

Replace `{processor_id}` with the CiviCRM payment processor ID. Use the Square
application and signature key for the same sandbox or production environment.
Subscribe to: `subscription.created`, `subscription.updated`,
`subscription.canceled`, `invoice.created`, `invoice.payment_made`,
`invoice.payment_failed`, `payment.updated`, `refund.created`, and
`refund.updated`.

`invoice.payment_made` is the preferred confirmation of a paid subscription
installment, because the invoice names its subscription. Check that existing
webhook subscriptions actually include it, not just `invoice.created`. Without it,
installments are still recorded from `payment.updated`, at the cost of one
Square invoice search per installment. `refund.updated` is needed to record
refunds made in the Square Dashboard, since `refund.created` usually reports a
refund that is still `PENDING`.

## Processing lifecycle

1. CiviCRM receives the request at the payment notification URL.
2. The extension validates the `X-Square-Hmacsha256-Signature` header against
   the raw body and CiviCRM notification URL.
3. Supported events are deduplicated by Square event ID and recorded in
   `civicrm_paymentprocessor_webhook`, in status `new`.
4. The record is processed immediately and marked:
   - `success` when processed;
   - `new` after a transient failure — Square unavailable (network error,
     HTTP 5xx or 429), or a related CiviCRM record not visible yet because
     webhooks arrived before the checkout finished saving. The
     **Process Payment Processor Webhooks** scheduled job retries `new`
     records on every run, for up to 72 hours after the event arrived;
   - `error` for anything else, including an amount mismatch (see below).
     These are not retried automatically.
5. If the request dies while processing (e.g. a PHP fatal error), the record
   stays `new` and the scheduled job retries it. A record left in
   `processing` was interrupted during a scheduled-job run; mjwshared's
   system check reports these after an hour — use the webhook queue's
   **Retry** action once the cause is fixed.

Unsupported events are acknowledged but not processed.

## Recurring installments

Square charges every installment of a subscription itself, including the
first. Its webhooks for an installment are, in order: `invoice.created` (a
draft — nothing is recorded), then `payment.updated` and
`invoice.payment_made`. The payment carries only the invoice's `order_id`,
not the subscription, so it is matched to its invoice at Square.

- The **first installment** completes the Pending contribution CiviCRM created
  at checkout; it never creates a new one.
- **Later installments** become new contributions via
  `Contribution.repeattransaction` (keeping the series' financial type and
  line items), then are completed.
- Payments are recorded with `Payment.create`. The Square payment ID becomes
  the payment's and the contribution's transaction ID, so a replayed or
  duplicate event for the same payment records nothing further.
- If Square's amount or currency differs from CiviCRM's, nothing is recorded
  and the queue record is marked `error` with a reconciliation message.
  CiviCRM's amounts are never rewritten to match Square.
- Square reports its processing fee in a later `payment.updated`; it is then
  recorded as the contribution's fee.
- The first installment's contribution is receipted once: not if the checkout
  already sent a receipt (webform_civicrm does), otherwise according to the
  recurring contribution's **Send email receipt** setting. Later installments
  are not receipted.

## Troubleshooting

| Symptom | Check |
|---|---|
| HTTP 401 | Verify the webhook signature key and the exact configured URL. Scheme, hostname, path, proxy rewriting, and processor ID all matter. |
| HTTP 400 | Check that Square posted valid JSON. |
| No CiviCRM update | Inspect the queue record's event type, processor ID, status, and error message. |
| Repeated delivery | Resolve the queue-record error; duplicate event IDs are otherwise skipped. |
| First recurring contribution stays Pending | Check the queue for that subscription's `payment.updated` / `invoice.payment_made` records and their messages. |

Do not log or share access tokens, signature keys, card tokens, raw webhook
bodies, or unredacted Square responses. Enable temporary verbose diagnostics at
**Administer → System Settings → Square Settings** and disable them afterwards.
