# Webhooks and operations

## Configure Square

Each CiviCRM Square processor needs a Square webhook subscription, in the
Square application whose access token it uses, sending events to:

```
https://your-site.example/civicrm/payment/ipn/{processor_id}
```

`{processor_id}` is the CiviCRM payment processor ID. Square only sends
webhooks to `https://` URLs. The required events are `subscription.created`,
`subscription.updated`, `invoice.created`, `invoice.payment_made`,
`invoice.scheduled_charge_failed`, `payment.updated`, `refund.created`,
`refund.updated`, `card.disabled`, `card.forgotten`,
`card.automatically_updated`, and `card.updated`.

### Automatic setup

**Administer → System Settings → Square Webhooks** lists each active Square
processor (live and test), its webhook URL, and what is wrong with its
subscription. **Create / update webhooks** then, for each processor:

- creates the subscription if there is none for its URL, and saves the
  signature key Square generates on the processor (its Subject field);
- otherwise enables it and adds any missing events, keeping any others it
  sends;
- generates a new signature key when the processor has none, or when **Also
  generate new signature keys** is ticked (use this after "Square IPN:
  signature validation failed" errors), and saves it on the processor;
- with **Enable Square event search** ticked (the default), enables Square's
  event search, so missed webhooks can be recovered (see below).

The access token must be allowed to manage webhook subscriptions, as the
application's own access token is. The System Status check (see below) warns
when a subscription is missing or incomplete, with a link to this page.

### Manual setup

To set it up by hand instead, create the subscription in the Square Developer
Dashboard with the URL and events above, and copy its signature key into the
processor's **Square Webhook Signature Key** field. Use the application and
signature key of the same sandbox or production environment.

The `card.*` events keep payment tokens up to date (`card.automatically_updated`
and `card.updated` update a card's expiry date and last 4 digits), and warn when
a card a recurring contribution still charges was disabled or forgotten
(`card.disabled`, `card.forgotten`). Subscriptions set up before these events
were handled lack them: **Create / update webhooks** adds them.

Square has no `subscription.canceled` or `invoice.payment_failed` event,
although earlier versions of this extension listed them. A cancellation
arrives as `subscription.updated` with a `canceled_date` (the end of the
period already paid for; the status stays `ACTIVE` until then, and Square may
send no further webhook when it becomes `CANCELED`), and a failed charge
of a subscription invoice as `invoice.scheduled_charge_failed`. Check that
existing webhook subscriptions include `invoice.scheduled_charge_failed`:
without it, failed renewals are never recorded in CiviCRM.

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
     webhooks arrived before the checkout finished saving, or out of order
     (e.g. a refund before the payment it refunds). The
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

[WORKFLOWS.md](WORKFLOWS.md#subscription-installment-webhooks) has diagrams of
the steps below.

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
- If checkout could not confirm the subscription (e.g. Square's response
  timed out), the recurring contribution has no subscription ID, but Square
  still bills the subscription. The first webhook about it links the two,
  from the recurring contribution and contact checkout stored in the
  subscription's source — only if that recurring contribution belongs to this
  processor and is not linked yet, and its contact is the one this processor
  mapped the subscription's Square customer to. Each link made this way is
  logged as a warning.
- A failed charge (`invoice.scheduled_charge_failed`) is checked against the
  invoice's current status at Square: if it has been paid since (webhooks can
  arrive out of order, and Square retries failed charges), nothing is marked
  Failed.

## Recovering missed webhooks

If Square cannot deliver a webhook (the site was down, the subscription was
missing an event, or it pointed at the wrong URL), it retries for a while and
then gives up. The **Square: recover missed webhook events** scheduled job
(hourly; `Square.recoverevents` API) fetches the events of the last 3 days from
Square's Events API and processes each one CiviCRM never received, exactly as if
its webhook had just arrived; events already received, in any queue state, are
skipped. It records a warning in the CiviCRM log when it recovers anything.

- Square only finds events from after its event search was enabled (see
  [Automatic setup](#automatic-setup)), and only for 28 days. The job skips a
  processor whose event search was never enabled from CiviCRM.
- Like every scheduled job, it does not run on a site whose environment is not
  Production, so a copy of a live site never imports live events.
- To look further back, run it by hand, e.g.
  `cv api3 Square.recoverevents since="-20 days"`, optionally with
  `payment_processor_id`.
- It does not reprocess events whose queue record is in `error`: fix the cause
  and use the webhook queue's **Retry** action for those.

## Reconciliation

**Administer → System Settings → Square Reconciliation** compares what Square
recorded with what CiviCRM recorded, for one processor, and processes what
CiviCRM missed. Square cannot be asked to send a webhook again (it retries a
failed delivery for 24 hours, then discards it), so the page processes records
itself. Look up:

- **a subscription and its installments**, by Square subscription ID or by the
  ID of the recurring contribution linked to it: the subscription, then each of
  its invoices (newest first) with the payment that paid it;
- **a Square payment or refund**, by its ID;
- **payments or refunds in a date range**, at the processor's Square location;
- **Square events in a date range**, from the Events API (last 28 days, and
  only once event search is enabled).

For each, the page shows Square's amount and status, whether CiviCRM recorded it
(with a link to the contribution or recurring contribution, or the amount
CiviCRM recorded if it differs), and the latest webhook queue record about it:
its status and message, or "never received". At most 200 are shown at once.

Select records and:

- **Process selected** turns each into the webhook Square would have sent,
  from Square's current data (`payment.updated`, `refund.updated`,
  `invoice.payment_made` for a paid installment, `subscription.updated` to
  sync a subscription's status), or for an event, the event itself, and
  processes it exactly as a webhook: in the webhook queue, as a record whose
  event ID starts `civicrm-reconcile-`, with the same checks. A payment already
  recorded is never recorded again, and an amount that differs from CiviCRM's is
  reported, not changed. Records CiviCRM already recorded, unpaid invoices,
  refunds Square has not completed, payments at other locations, and events
  CiviCRM already received cannot be selected for this.
- **Retry selected webhook queue records** processes again, now, the queue
  records that failed or are waiting, as the queue's own **Retry** action
  does (without waiting for cron), once the cause of the failure is fixed.

The page then shows the search again, with the outcome. The search is in the
page's URL, so it can be reloaded or shared with another administrator.

## System Status checks

**Administer → Administration Console → System Status** reports, for the
Square processors:

- a missing setting, credentials Square refuses, or an inactive Square location;
- contribution pages or upcoming events using a processor whose Square location
  takes another currency (Square takes payments in the location's currency
  only);
- on production sites, a missing, disabled or incomplete webhook subscription,
  a signature key that does not match it, and event search not enabled;
- Square webhooks in `error` in the last 30 days, and webhooks waiting more than
  2 hours in `new` (cron or the **Process PaymentProcessor Webhooks** job not
  running);
- an mjwshared extension older than 1.5.5;
- for live processors without the Firewall extension, a reminder about
  card-testing protection.

## Refunds

- Refunds made from CiviCRM's refund form (`doRefund()`) are recorded only
  once Square has completed them. Square accepts a card refund as `PENDING`
  until it has the funds, which can take a while and can end in `FAILED`.
  `doRefund()` checks on it for a few seconds; one still `PENDING` is reported
  to staff as not recorded yet, and `refund.updated` records it when Square
  completes it. Don't refund the payment again in the meantime. The refund
  form cancels the event registrations and memberships selected on it only
  for a completed refund, so cancel those by hand.
- Refunds made in the Square Dashboard are recorded from `refund.updated` once
  completed.
- A refund that arrives before the payment it refunds is recorded is retried
  (for 30 minutes after Square created it), since Square does not deliver
  webhooks in order.

## Troubleshooting

| Symptom | Check |
|---|---|
| HTTP 401 | Verify the webhook signature key and the exact configured URL. Scheme, hostname, path, proxy rewriting, and processor ID all matter. **Square Webhooks** shows the URL CiviCRM expects; tick **Also generate new signature keys** there to replace a key that does not match. |
| HTTP 400 | Check that Square posted valid JSON. |
| No CiviCRM update | Inspect the queue record's event type, processor ID, status, and error message. |
| Repeated delivery | Resolve the queue-record error; duplicate event IDs are otherwise skipped. |
| First recurring contribution stays Pending | Check the queue for that subscription's `payment.updated` / `invoice.payment_made` records and their messages. |
| An event never arrived | Run `Square.recoverevents` (see [Recovering missed webhooks](#recovering-missed-webhooks)), then check the subscription on **Square Webhooks**. |

Do not log or share access tokens, signature keys, card tokens, raw webhook
bodies, or unredacted Square responses. Enable temporary verbose diagnostics at
**Administer → System Settings → Square Settings** and disable them afterwards.
