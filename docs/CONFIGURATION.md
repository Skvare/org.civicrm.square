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

The Square location takes payments in one currency only (its country's). A
payment in any other currency is refused before Square is asked, with a message
saying so, and the System Status check lists contribution pages and upcoming
events that use the processor in another currency.

## Go-live process

1. Put the processor in test mode and configure sandbox credentials.
2. Create the matching sandbox webhook subscription at **Administer → System
   Settings → Square Webhooks** (or by hand: see [WEBHOOKS.md](WEBHOOKS.md)).
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

On the back-office credit card contribution form, staff can set a **Start
Date** for a recurring contribution: the Square subscription then starts on
that date, and Square charges the first installment then (and each later one
on the same cycle). The contribution stays Pending until then. Any start date
not after today starts the subscription today, as on public forms.

Staff (and donors, on self-service links) can change a recurring
contribution's amount from CiviCRM; the change is sent to the Square
subscription, applying from its next installment. Changing the number of
installments is refused, since Square cannot change it on an existing
subscription, as is changing the amount of a recurring contribution with
more than one line item.

The card a recurring contribution is charged to can be replaced from
**Update billing details** (e.g. after the card expires): the new card is saved
to the subscription's Square customer and Square charges it from the next
installment.

## Square Settings

**Administer → System Settings → Square Settings**:

| Setting | Use |
|---|---|
| Square IPN Debug Logging | Verbose webhook diagnostics in the CiviCRM log. Leave off in normal operation. |
| Import Square payments made outside CiviCRM | Record completed payments taken at the processor's location outside CiviCRM (e.g. the Square Dashboard) as Donations. |
| Minimum card payment amount | Card payments and recurring installments below it are refused on public forms before Square is asked; staff on back-office forms are not limited. Empty for no minimum. |
| Card statement description | Up to 20 characters Square adds to the payer's card statement for one-time payments (Square's `statement_description_identifier`), after the business name. Not used for subscription installments. |

## Card-testing protection

Public payment forms attract card testing: many small payments with stolen card
numbers. To limit it:

- set a **Minimum card payment amount** above what testers try (often $1);
- install the [Firewall extension](https://civicrm.org/extensions/firewall).
  Each card Square declines on a public form is reported to it as a declined-card
  event, and it blocks an IP address that causes too many. Declines on
  back-office forms are not reported. The System Status check reminds you while
  Firewall is not installed.

Square's own Risk Manager and the buyer verification done when the card is
tokenized apply either way.

## Square customers

Each contact who pays by recurring contribution gets a Square customer. When
the contact's name, or an email, is saved in CiviCRM, the contact's Square
customers (on every Square processor) are updated with the current first and
last name and primary email, at the end of that request. Square is only called
when those details changed since the last update, and a failure is only logged. Like the
scheduled jobs, this does nothing on a site whose environment is not
Production.

