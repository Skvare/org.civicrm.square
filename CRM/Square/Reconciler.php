<?php

use Civi\Api4\Contact;
use Civi\Api4\Contribution;
use Civi\Api4\ContributionRecur;
use Civi\Api4\FinancialTrxn;
use Civi\Api4\Payment;
use Square\SquareClient;
use Square\Types\InvoiceFilter;
use Square\Types\InvoiceQuery;
use Square\Types\InvoiceSort;
use Square\Subscriptions\Requests\GetSubscriptionsRequest;
use Square\Invoices\Requests\SearchInvoicesRequest;
use Square\Orders\Requests\GetOrdersRequest;

/**
 * Keeps CiviCRM's financial records in step with Square, from its webhooks.
 *
 * Records Square payments, subscription installments and refunds against
 * the right contributions (always through CiviCRM's Payment.create, never
 * by writing a status or amount onto a contribution), and applies Square
 * subscription status changes to recurring contributions. CiviCRM stays the
 * authority for contributions, line items and the ledger; Square is the
 * authority for what was charged.
 *
 * The protected find/record/update methods at the end are its only access
 * to CiviCRM data, so tests can replace them.
 */
class CRM_Square_Reconciler {

  /**
   * Square API access for the payment processor.
   *
   * @var \CRM_Square_Gateway
   */
  protected CRM_Square_Gateway $gateway;

  /**
   * Builds the reconciler for one payment processor.
   *
   * @param \CRM_Square_Gateway $gateway
   */
  public function __construct(CRM_Square_Gateway $gateway) {
    $this->gateway = $gateway;
  }

  /**
   * How long, in seconds, after Square created a payment or invoice its webhook may be deferred.
   *
   * Webhooks routinely arrive before the request that caused them has
   * finished saving: Square charges a new subscription's first invoice
   * within seconds of doRecurPayment() creating it, and a one-time payment's
   * webhook can beat CiviCRM's own checkout to recording it. While an event
   * is younger than this, "the matching CiviCRM record is not there yet" is
   * treated as a retryable race rather than a reason to create a new
   * contribution. Well past this age the race is over, so the event is
   * handled on its own merits.
   */
  protected const WEBHOOK_GRACE_SECONDS = 1800;

  /**
   * Sync a Square payment (from a payment.updated webhook) into CiviCRM.
   *
   * In order:
   *  1. Already recorded as a payment by this processor: nothing to do.
   *  2. A one-time payment CiviCRM itself initiated: matched by trxn_id, or
   *     by invoice_id == reference_id (doOneTimePayment() sends CiviCRM's
   *     invoiceID as Square's reference_id), and reconciled.
   *  3. A Square subscription installment. Square's payment carries neither
   *     subscription_id nor reference_id — only order_id — so the invoice
   *     that owns that order is looked up at Square, and the installment is
   *     recorded against the subscription's recurring contribution exactly
   *     as for invoice.payment_made (see recordSubscriptionInstallment()).
   *  4. Anything else is a payment taken outside CiviCRM, imported as a new
   *     contribution — unless it may still turn out to be one of the above
   *     whose CiviCRM records are not visible yet, in which case it is
   *     retried rather than imported as a duplicate.
   *
   * @param array $payment
   *   Payment object from Square webhooks.
   *
   * @throws \CRM_Core_Exception
   */
  public function syncPaymentFromSquare(array $payment) {
    $paymentId = $payment['id'] ?? NULL;
    $status = strtoupper((string) ($payment['status'] ?? 'UNKNOWN'));
    CRM_Core_Payment_SquareDebugLogger::log("Square syncPaymentFromSquare(): called from SquareIPN for payment_id={$paymentId}, status={$status}, order_id=" . ($payment['order_id'] ?? 'null'));
    if (!$paymentId) {
      CRM_Core_Payment_SquareDebugLogger::log('Square syncPaymentFromSquare(): missing payment ID, skipping.');
      return;
    }

    $lock = $this->acquireSquareLock('payment.' . $paymentId);
    try {
      $recorded = $this->findRecordedPayment($paymentId);
      if ($recorded) {
        CRM_Core_Payment_SquareDebugLogger::log("Square syncPaymentFromSquare(): payment {$paymentId} is already recorded.");
        $this->recordProcessingFee($recorded, $payment);
        return;
      }

      $existing = $this->findContributionForSquarePayment($paymentId, $payment['reference_id'] ?? NULL);
      if ($existing) {
        $this->reconcileExistingContributionPayment($existing, $payment);
        return;
      }

      if ($status !== 'COMPLETED') {
        CRM_Core_Payment_SquareDebugLogger::log("Square syncPaymentFromSquare(): payment {$paymentId} matches no contribution and is {$status}; nothing is recorded until Square reports it COMPLETED.");
        return;
      }

      $invoice = $this->findInvoiceForPayment($payment);
      if (!empty($invoice['subscription_id'])) {
        $recur = $this->findRecurBySubscriptionId($invoice['subscription_id']);
        if (!$recur) {
          $this->handleUnknownSubscription($invoice['subscription_id'], $payment['created_at'] ?? NULL, "payment {$paymentId}");
          return;
        }
        $this->recordSubscriptionInstallment($recur, $this->installmentFromPayment($payment, $invoice));
        return;
      }

      if (!$invoice && $this->mayBeAwaitingCiviRecord($payment)) {
        throw new CRM_Core_Payment_SquareRetryableException("Square payment {$paymentId} matches no CiviCRM contribution or Square subscription invoice yet; retrying in case it belongs to a checkout or subscription that is still being saved.");
      }

      $this->createContributionFromSquarePayment($payment);
    }
    finally {
      $lock->release();
    }
  }

  /**
   * Record Square's processing fee for an already-recorded payment, if not yet known.
   *
   * Square reports the fee in a later payment.updated than the one that
   * first reports the payment COMPLETED. Setting the contribution's
   * fee_amount makes CiviCRM record the fee transaction. The payment's own
   * fee_amount is set first: every later Payment.create that carries a
   * fee_amount (mjwshared's refund form sends 0) recalculates the
   * contribution's fee as the total of its payments' fees.
   *
   * @param array $recorded
   *   As returned by findRecordedPayment().
   * @param array $payment
   *   Payment object from Square webhooks.
   *
   * @throws \CRM_Core_Exception
   */
  protected function recordProcessingFee(array $recorded, array $payment): void {
    $fee = $this->sumProcessingFees($payment['processing_fee'] ?? []);
    if (empty($fee) || empty($recorded['contribution_id'])) {
      return;
    }
    $contributionId = (int) $recorded['contribution_id'];
    if ($this->getContributionFeeAmount($contributionId) > 0) {
      return;
    }
    $this->updatePaymentFee((int) $recorded['id'], $fee, (float) $recorded['total_amount']);
    $this->updateContribution($contributionId, ['fee_amount' => $fee]);
    CRM_Core_Payment_SquareDebugLogger::log("Square syncPaymentFromSquare(): recorded processing fee {$fee} on contribution {$contributionId} for payment {$payment['id']}.");
  }

  /**
   * Whether an unmatched payment may belong to a CiviCRM record not visible yet.
   *
   * A reference_id is only ever sent by CiviCRM's own checkout (see
   * doOneTimePayment()), and a card-on-file charge for a Square customer is
   * how Square bills subscription invoices. Either, while still recent,
   * means "not found" is more likely a race than an external payment.
   *
   * @param array $payment
   *
   * @return bool
   */
  protected function mayBeAwaitingCiviRecord(array $payment): bool {
    if (!$this->isWithinWebhookGrace($payment['created_at'] ?? NULL)) {
      return FALSE;
    }
    $isCardOnFileCharge = !empty($payment['customer_id'])
      && strtoupper((string) ($payment['card_details']['entry_method'] ?? '')) === 'ON_FILE';
    return !empty($payment['reference_id']) || $isCardOnFileCharge;
  }

  /**
   * Reconcile a Square payment against an existing one-time contribution.
   *
   * Amount/currency mismatches are never silently corrected — they are
   * raised as reconciliation errors requiring manual review, preserving
   * whatever line items and financial allocations the contribution already
   * has. Completion is only ever recorded via Payment.create so the
   * financial ledger stays accurate.
   *
   * @param array $existing
   *   Contribution, as returned by findContributionForSquarePayment().
   * @param array $payment
   *   Payment object from Square webhooks.
   *
   * @throws \CRM_Core_Exception
   */
  protected function reconcileExistingContributionPayment(array $existing, array $payment): void {
    $contributionId = (int) $existing['id'];
    $paymentId = (string) $payment['id'];
    $status = strtoupper((string) ($payment['status'] ?? 'UNKNOWN'));

    if ($existing['status'] === 'Completed') {
      CRM_Core_Payment_SquareDebugLogger::log("Square syncPaymentFromSquare(): contribution {$contributionId} already Completed for payment {$paymentId}, skipping.");
      return;
    }
    if ($existing['status'] === 'Pending' && CRM_Square_Status::mapPaymentStatus($status) === CRM_Square_Status::contributionStatusId('Failed')) {
      $this->updateContribution($contributionId, ['contribution_status_id' => CRM_Square_Status::contributionStatusId('Failed')]);
      CRM_Core_Payment_SquareDebugLogger::log("Square syncPaymentFromSquare(): payment {$paymentId} is {$status}; marked contribution {$contributionId} Failed.");
      return;
    }
    if ($status !== 'COMPLETED') {
      CRM_Core_Payment_SquareDebugLogger::log("Square syncPaymentFromSquare(): payment {$paymentId} status '{$status}' does not indicate completion, leaving contribution {$contributionId} unchanged.");
      return;
    }

    // Matched only by invoice_id means the checkout has not saved this
    // payment's trxn_id yet: it is normally about to record the payment
    // itself, and recording it here too would be a second payment on the
    // same contribution. Leave it to the checkout unless it never does.
    $claimedByPayment = in_array($paymentId, explode(',', (string) ($existing['trxn_id'] ?? '')), TRUE);
    if (!$claimedByPayment && $this->isWithinWebhookGrace($payment['created_at'] ?? NULL)) {
      throw new CRM_Core_Payment_SquareRetryableException("Contribution {$contributionId} is still Pending while CiviCRM's checkout records Square payment {$paymentId}; retrying later.");
    }

    $this->assertAmountMatches((float) $existing['total_amount'], (string) $existing['currency'], $this->installmentFromPayment($payment, []), "contribution {$contributionId}");
    $this->recordContributionPayment($contributionId, [
      'total_amount' => (float) $existing['total_amount'],
      'trxn_id' => $paymentId,
      'trxn_date' => $this->squareTimestampToCivi($payment['created_at'] ?? NULL),
      'payment_instrument_id' => CRM_Square_Status::mapPaymentInstrument($payment['source_type'] ?? NULL),
      'fee_amount' => $this->sumProcessingFees($payment['processing_fee'] ?? []),
    ], FALSE);
    CRM_Core_Payment_SquareDebugLogger::log("Square syncPaymentFromSquare(): completed contribution {$contributionId} for payment {$paymentId} via Payment.create.");
  }

  /**
   * Import a completed Square payment taken outside CiviCRM as a new contribution.
   *
   * Only when the square_import_external_payments setting is on, and only
   * for payments at this processor's location: Square sends webhooks for
   * every payment the merchant takes — point of sale, Square Online, other
   * locations — and none of those is CiviCRM's to record by default.
   *
   * @param array $payment
   *   Payment object from Square webhooks.
   *
   * @throws \CRM_Core_Exception
   */
  protected function createContributionFromSquarePayment(array $payment): void {
    $paymentId = (string) $payment['id'];
    $orderID = $payment['order_id'] ?? NULL;
    $referenceId = $payment['reference_id'] ?? NULL;

    if (!$this->isImportingExternalPayments()) {
      CRM_Core_Payment_SquareDebugLogger::log("Square syncPaymentFromSquare(): payment {$paymentId} matches nothing in CiviCRM; importing Square payments made outside CiviCRM is disabled, so it is ignored.");
      return;
    }
    $locationId = $payment['location_id'] ?? NULL;
    if ($locationId !== $this->gateway->getLocationId()) {
      CRM_Core_Payment_SquareDebugLogger::log("Square syncPaymentFromSquare(): payment {$paymentId} was taken at location " . ($locationId ?? 'unknown') . ", not this processor's; ignored.");
      return;
    }
    CRM_Core_Payment_SquareDebugLogger::log("Square syncPaymentFromSquare(): no existing contribution found for payment {$paymentId} (order_id={$orderID}), attempting to create a new one.");

    // Payments made by versions of doRecurPayment() before the subscription
    // billed its own first installment used the recurring contribution ID
    // as their reference_id.
    $contactId = NULL;
    $refRecurContribution = NULL;
    if ($referenceId && ctype_digit((string) $referenceId)) {
      $refRecurContribution = ContributionRecur::get(FALSE)
        ->addSelect('id', 'contact_id', 'financial_type_id')
        ->addWhere('id', '=', (int) $referenceId)
        ->addWhere('payment_processor_id', '=', $this->gateway->processorId())
        ->addWhere('is_test', '=', $this->gateway->isTestMode())
        ->execute()
        ->first();
      if ($refRecurContribution) {
        $contactId = (int) $refRecurContribution['contact_id'];
      }
    }

    if (!$contactId) {
      $contactId = $this->findContactIdForPayment($payment);
    }

    if (!$contactId) {
      CRM_Core_Payment_SquareDebugLogger::log("Square syncPaymentFromSquare(): cannot resolve contact for payment {$paymentId}, skipping create.");
      return;
    }

    // No better mapping available (no recur template) — fall back to the
    // Donation financial type resolved by name, never a hard-coded ID.
    $financialTypeId = $refRecurContribution['financial_type_id'] ?? CRM_Square_Status::financialTypeId('Donation');
    $money = $payment['amount_money'] ?? [];
    $amount = isset($money['amount']) ? ((float) $money['amount']) / 100 : 0.0;

    // Create the contribution Pending, then complete it via Payment.create so
    // the CiviCRM financial ledger (FinancialTrxn / EntityFinancialTrxn) is
    // populated correctly — never write a "Completed" status directly onto
    // the contribution row.
    $values = [
      'contact_id' => $contactId,
      'financial_type_id' => $financialTypeId,
      'total_amount' => $amount,
      'currency' => $money['currency'] ?? 'USD',
      'contribution_status_id' => CRM_Square_Status::contributionStatusId('Pending'),
      'trxn_id' => $paymentId,
      'is_test' => $this->gateway->isTestMode(),
      'source' => 'Square Payment (Webhook)',
    ];
    if ($orderID !== NULL) {
      $values['invoice_number'] = $orderID;
    }
    $newContributionId = $this->createContribution($values);

    $this->recordContributionPayment($newContributionId, [
      'total_amount' => $amount,
      'trxn_id' => $paymentId,
      'trxn_date' => $this->squareTimestampToCivi($payment['created_at'] ?? NULL),
      'payment_instrument_id' => CRM_Square_Status::mapPaymentInstrument($payment['source_type'] ?? NULL),
      'fee_amount' => $this->sumProcessingFees($payment['processing_fee'] ?? []),
    ], FALSE);
    CRM_Core_Payment_SquareDebugLogger::log("Square syncPaymentFromSquare(): Created and completed contribution {$newContributionId} for payment {$paymentId}, contact {$contactId}.");
  }

  /**
   * Find the Square invoice a payment paid, if any.
   *
   * Square payment objects do not reference the invoice (or subscription)
   * they pay, only the invoice's order. Square's order does not reference
   * its invoice either, so the customer's invoices at the payment's
   * location are searched, newest first, for the one owning that order.
   *
   * @param array $payment
   *   Payment object from Square webhooks.
   *
   * @return array|null
   *   The invoice (REST JSON shape), or NULL if the payment paid no invoice.
   *
   * @throws \CRM_Core_Exception
   */
  protected function findInvoiceForPayment(array $payment): ?array {
    $orderId = $payment['order_id'] ?? NULL;
    $customerId = $payment['customer_id'] ?? NULL;
    if (empty($orderId) || empty($customerId)) {
      // Subscription invoices are always billed to a Square customer.
      return NULL;
    }
    $locationId = $payment['location_id'] ?? $this->gateway->getLocationId();

    $cursor = NULL;
    // A subscription's invoice is created moments before Square charges it,
    // so it is among the customer's newest; stop after a few pages.
    for ($page = 0; $page < 3; $page++) {
      $request = new SearchInvoicesRequest([
        'query' => new InvoiceQuery([
          'filter' => new InvoiceFilter([
            'locationIds' => [$locationId],
            'customerIds' => [$customerId],
          ]),
          'sort' => new InvoiceSort(['field' => 'INVOICE_SORT_DATE', 'order' => 'DESC']),
        ]),
        'limit' => 100,
        'cursor' => $cursor,
      ]);
      $response = $this->gateway->call(fn (SquareClient $client) => $client->invoices->search($request));
      foreach ($response->getInvoices() ?? [] as $invoice) {
        if ($invoice->getOrderId() === $orderId) {
          return $invoice->jsonSerialize();
        }
      }
      $cursor = $response->getCursor();
      if (empty($cursor)) {
        break;
      }
    }
    return NULL;
  }

  /**
   * Describe a paid Square payment as a subscription installment.
   *
   * @param array $payment
   *   Payment object from Square webhooks.
   * @param array $invoice
   *   The Square invoice it paid, if known.
   *
   * @return array
   *   See recordSubscriptionInstallment().
   */
  protected function installmentFromPayment(array $payment, array $invoice): array {
    $money = $payment['amount_money'] ?? [];
    return [
      'payment_id' => (string) $payment['id'],
      'invoice_id' => $invoice['id'] ?? NULL,
      'amount' => isset($money['amount']) ? ((float) $money['amount']) / 100 : NULL,
      'currency' => $money['currency'] ?? NULL,
      'fee_amount' => $this->sumProcessingFees($payment['processing_fee'] ?? []),
      'trxn_date' => $this->squareTimestampToCivi($payment['created_at'] ?? NULL),
      'payment_instrument_id' => CRM_Square_Status::mapPaymentInstrument($payment['source_type'] ?? NULL),
    ];
  }

  /**
   * Describe a paid Square subscription invoice as an installment.
   *
   * The invoice does not carry the ID of the payment that paid it, so it is
   * read from the tender on the invoice's order — never invented, since the
   * Square payment ID is what every later event (payment.updated,
   * refund.*) identifies the payment by.
   *
   * @param array $invoice
   *   Invoice object from Square webhooks.
   *
   * @return array
   *   See recordSubscriptionInstallment().
   *
   * @throws \CRM_Core_Exception
   */
  protected function installmentFromInvoice(array $invoice): array {
    $invoiceId = $invoice['id'];
    $orderId = $invoice['order_id'] ?? NULL;
    if (empty($orderId)) {
      throw new CRM_Core_Exception("Square invoice {$invoiceId} has no order_id, so the payment that paid it cannot be identified.");
    }

    $tenders = array_values(array_filter(
      $this->getSquareOrderTenders($orderId),
      fn (array $tender) => !empty($tender['payment_id'])
    ));
    if (!$tenders) {
      throw new CRM_Core_Payment_SquareRetryableException("Square order {$orderId} of paid invoice {$invoiceId} does not show its payment yet.");
    }
    if (count($tenders) > 1) {
      $message = "Square invoice {$invoiceId} was paid with " . count($tenders) . ' separate payments; recording split payments is not supported and needs manual reconciliation.';
      Civi::log()->error('Square: ' . $message);
      throw new CRM_Core_Exception($message);
    }

    $tender = $tenders[0];
    $money = $tender['amount_money'] ?? [];
    return [
      'payment_id' => (string) $tender['payment_id'],
      'invoice_id' => $invoiceId,
      'amount' => isset($money['amount']) ? ((float) $money['amount']) / 100 : NULL,
      'currency' => $money['currency'] ?? NULL,
      'fee_amount' => isset($tender['processing_fee_money']['amount']) ? ((float) $tender['processing_fee_money']['amount']) / 100 : NULL,
      'trxn_date' => $this->squareTimestampToCivi($tender['created_at'] ?? $invoice['updated_at'] ?? NULL),
      'payment_instrument_id' => CRM_Square_Status::mapPaymentInstrument($tender['type'] ?? NULL),
    ];
  }

  /**
   * Fetch the tenders (payments) recorded on a Square order.
   *
   * @param string $orderId
   *
   * @return array
   *   Tenders in REST JSON shape.
   *
   * @throws \CRM_Core_Exception
   */
  protected function getSquareOrderTenders(string $orderId): array {
    $order = $this->gateway->call(fn (SquareClient $client) => $client->orders->get(
      new GetOrdersRequest(['orderId' => $orderId])
    ))->getOrder();
    if (empty($order)) {
      throw new CRM_Core_Payment_SquareRetryableException("Square order {$orderId} was not found.");
    }
    return $order->jsonSerialize()['tenders'] ?? [];
  }

  /**
   * Record a subscription installment that Square has confirmed as paid.
   *
   * The first installment completes the Pending contribution CiviCRM created
   * at checkout (see findSignupContribution()); later installments are
   * cloned from the series with Contribution.repeattransaction. Either way
   * the payment itself is recorded with Payment.create, never by writing a
   * status onto the contribution.
   *
   * The Square payment ID is the idempotency key: it becomes both the
   * contribution's trxn_id (unique in civicrm_contribution) and the
   * payment's trxn_id, so a replayed or concurrent webhook for the same
   * payment — whether it arrives as invoice.payment_made or payment.updated
   * — records nothing further. Installments of one series are recorded
   * one at a time (under a lock on the series), so two payments can never
   * both claim the checkout's contribution.
   *
   * @param array $recur
   *   Recurring contribution, as returned by findRecurBySubscriptionId().
   * @param array $installment
   *   The payment: payment_id, invoice_id (Square invoice, if known),
   *   amount, currency, fee_amount, trxn_date, payment_instrument_id.
   *
   * @throws \CRM_Core_Exception
   */
  protected function recordSubscriptionInstallment(array $recur, array $installment): void {
    $lock = $this->acquireSquareLock('recur.' . $recur['id']);
    try {
      $this->recordSubscriptionInstallmentLocked($recur, $installment);
    }
    finally {
      $lock->release();
    }
  }

  /**
   * Body of recordSubscriptionInstallment(), run under its lock.
   *
   * @param array $recur
   * @param array $installment
   *
   * @throws \CRM_Core_Exception
   */
  private function recordSubscriptionInstallmentLocked(array $recur, array $installment): void {
    $paymentId = $installment['payment_id'];
    $recurId = (int) $recur['id'];

    if ($this->findRecordedPayment($paymentId)) {
      CRM_Core_Payment_SquareDebugLogger::log("Square recordSubscriptionInstallment(): payment {$paymentId} is already recorded, nothing to do.");
      return;
    }

    $contributions = $this->getRecurContributions($recurId);
    $contribution = $this->findInstallmentContribution($contributions, $recur, $installment);

    if ($contribution === NULL) {
      // A second or later installment: nothing in CiviCRM represents it yet.
      $this->assertAmountMatches((float) $recur['amount'], (string) $recur['currency'], $installment, "recurring contribution {$recurId}");
      $contribution = $this->repeatRecurContribution($recur, [
        'contribution_status' => 'Pending',
        'trxn_id' => $paymentId,
        'invoice_id' => $installment['invoice_id'],
        'receive_date' => $installment['trxn_date'],
      ]);
      CRM_Core_Payment_SquareDebugLogger::log("Square recordSubscriptionInstallment(): created contribution {$contribution['id']} for payment {$paymentId} of recurring contribution {$recurId}.");
    }
    elseif ($contribution['status'] === 'Failed') {
      // Square collected an installment whose earlier attempt failed:
      // reopen that contribution (CiviCRM allows Failed -> Pending) rather
      // than record the payment on another one.
      $this->updateContribution((int) $contribution['id'], ['contribution_status_id' => CRM_Square_Status::contributionStatusId('Pending')]);
      $contribution['status'] = 'Pending';
    }
    elseif ($contribution['status'] === 'Completed') {
      CRM_Core_Payment_SquareDebugLogger::log("Square recordSubscriptionInstallment(): contribution {$contribution['id']} for payment {$paymentId} is already Completed, nothing to do.");
      return;
    }
    elseif ($contribution['status'] !== 'Pending') {
      $message = "Contribution {$contribution['id']} for Square payment {$paymentId} is {$contribution['status']}; the payment was not recorded and needs manual reconciliation.";
      Civi::log()->error('Square: ' . $message);
      throw new CRM_Core_Exception($message);
    }

    $this->assertAmountMatches((float) $contribution['total_amount'], (string) $contribution['currency'], $installment, "contribution {$contribution['id']}");

    // Claim the contribution for this payment before recording it. Payment
    // .create appends its trxn_id to the contribution's rather than
    // replacing it, and the unique trxn_id also stops any other contribution
    // being created for the same payment.
    if (($contribution['trxn_id'] ?? '') !== $paymentId) {
      $this->updateContribution((int) $contribution['id'], ['trxn_id' => $paymentId]);
    }

    // The checkout's contribution (always the series' oldest) is receipted
    // once: not if the checkout already sent a receipt (webform_civicrm does,
    // which sets receipt_date), otherwise as the recurring contribution's
    // receipt setting says — as CiviCRM does when a contribution page
    // completes the first payment itself. Later installments are not
    // receipted, as before.
    $isCheckoutContribution = !empty($contributions) && (int) $contribution['id'] === (int) $contributions[0]['id'];
    $sendReceipt = $isCheckoutContribution && !empty($recur['is_email_receipt']) && empty($contribution['receipt_date']);

    // Payment.create completes the contribution (preserving its line items
    // and financial allocations) and moves the recurring contribution from
    // Pending to In Progress via updateOnNewPayment().
    $this->recordContributionPayment((int) $contribution['id'], [
      'total_amount' => (float) $contribution['total_amount'],
      'trxn_id' => $paymentId,
      'trxn_date' => $installment['trxn_date'],
      'payment_instrument_id' => $installment['payment_instrument_id'],
      'fee_amount' => $installment['fee_amount'],
    ], $sendReceipt);
    CRM_Core_Payment_SquareDebugLogger::log("Square recordSubscriptionInstallment(): recorded payment {$paymentId} on contribution {$contribution['id']} of recurring contribution {$recurId}.");
  }

  /**
   * Find the contribution a subscription installment belongs to, if one exists.
   *
   * @param array $contributions
   *   The series' contributions, as returned by getRecurContributions().
   * @param array $recur
   *   Recurring contribution, as returned by findRecurBySubscriptionId().
   * @param array $installment
   *   See recordSubscriptionInstallment().
   *
   * @return array|null
   *   NULL when the installment needs a new contribution.
   */
  protected function findInstallmentContribution(array $contributions, array $recur, array $installment): ?array {
    // Already claimed by this payment (e.g. a retry after a failure between
    // claiming and recording it).
    foreach ($contributions as $contribution) {
      if (in_array($installment['payment_id'], explode(',', (string) ($contribution['trxn_id'] ?? '')), TRUE)) {
        return $contribution;
      }
    }
    // Already created for this Square invoice (e.g. by
    // invoice.scheduled_charge_failed for an earlier attempt).
    if (!empty($installment['invoice_id'])) {
      foreach ($contributions as $contribution) {
        if (($contribution['invoice_id'] ?? NULL) === $installment['invoice_id']) {
          return $contribution;
        }
      }
    }
    return $this->findSignupContribution($contributions, $recur);
  }

  /**
   * The Pending contribution CiviCRM created at checkout, while the first installment is unpaid.
   *
   * CiviCRM's checkout (contribution page or webform_civicrm) creates the
   * series' first contribution itself, Pending, before doRecurPayment()
   * creates the Square subscription — and Square then bills that first
   * installment as the subscription's first invoice. That invoice must
   * complete this contribution, not a new one.
   *
   * It can only be the first installment while nothing in the series has
   * been paid, and only if the contribution is not already claimed by a
   * different Square payment: its trxn_id is empty, or is the subscription
   * ID that versions of doRecurPayment() before this fix returned as the
   * checkout's trxn_id.
   *
   * @param array $contributions
   *   The series' contributions, oldest first, as returned by
   *   getRecurContributions().
   * @param array $recur
   *   Recurring contribution, as returned by findRecurBySubscriptionId().
   *
   * @return array|null
   */
  protected function findSignupContribution(array $contributions, array $recur): ?array {
    foreach ($contributions as $contribution) {
      if (!in_array($contribution['status'], ['Pending', 'Failed', 'Cancelled'], TRUE)) {
        return NULL;
      }
    }
    foreach ($contributions as $contribution) {
      $trxnId = (string) ($contribution['trxn_id'] ?? '');
      if ($contribution['status'] === 'Pending' && ($trxnId === '' || $trxnId === (string) $recur['processor_id'])) {
        return $contribution;
      }
    }
    return NULL;
  }

  /**
   * Refuse to record a payment whose amount or currency differs from CiviCRM's.
   *
   * CiviCRM's amount (and the line items and financial allocations behind
   * it) is never rewritten to match what Square reports.
   *
   * @param float $expectedAmount
   * @param string $expectedCurrency
   * @param array $installment
   *   See recordSubscriptionInstallment().
   * @param string $expectedBy
   *   What $expectedAmount belongs to, for the error message.
   *
   * @throws \CRM_Core_Exception
   */
  protected function assertAmountMatches(float $expectedAmount, string $expectedCurrency, array $installment, string $expectedBy): void {
    $actualCents = $installment['amount'] === NULL ? NULL : (int) round($installment['amount'] * 100);
    if ($actualCents === (int) round($expectedAmount * 100) && strcasecmp($expectedCurrency, (string) $installment['currency']) === 0) {
      return;
    }
    $message = sprintf(
      'Square payment %s was for %s %s but CiviCRM %s is for %0.2f %s; the payment was not recorded and needs manual reconciliation.',
      $installment['payment_id'],
      $installment['amount'] === NULL ? 'an unknown amount' : sprintf('%0.2f', $installment['amount']),
      $installment['currency'] ?? '',
      $expectedBy,
      $expectedAmount,
      $expectedCurrency
    );
    Civi::log()->error('Square: ' . $message);
    throw new CRM_Core_Exception($message);
  }

  /**
   * Handle an event for a Square subscription no recurring contribution here is linked to.
   *
   * Square bills a new subscription's first invoice within seconds of
   * doRecurPayment() creating it — possibly before the checkout has saved
   * the subscription ID on the recurring contribution — so a recent event
   * is retried. An older one belongs to a subscription this processor does
   * not manage (e.g. one created in the Square Dashboard) and is ignored.
   *
   * @param string $subscriptionId
   * @param string|null $createdAt
   *   When Square created the payment or invoice (ISO 8601).
   * @param string $context
   *   What the event is about, for messages.
   *
   * @throws \CRM_Core_Payment_SquareRetryableException
   */
  protected function handleUnknownSubscription(string $subscriptionId, ?string $createdAt, string $context): void {
    if ($this->isWithinWebhookGrace($createdAt)) {
      throw new CRM_Core_Payment_SquareRetryableException("No recurring contribution on payment processor {$this->gateway->processorId()} is linked to Square subscription {$subscriptionId} ({$context}) yet; retrying in case checkout is still saving it.");
    }
    CRM_Core_Payment_SquareDebugLogger::log("Square: subscription {$subscriptionId} ({$context}) is not linked to a recurring contribution on payment processor {$this->gateway->processorId()}; ignoring.");
  }

  /**
   * Whether a Square timestamp is recent enough that its webhook may be deferred.
   *
   * @param string|null $createdAt
   *   ISO 8601 timestamp from Square.
   *
   * @return bool
   */
  protected function isWithinWebhookGrace(?string $createdAt): bool {
    $created = empty($createdAt) ? FALSE : strtotime($createdAt);
    return $created !== FALSE && (time() - $created) < self::WEBHOOK_GRACE_SECONDS;
  }

  /**
   * Convert a Square ISO 8601 timestamp to a CiviCRM (site-local) datetime.
   *
   * @param string|null $timestamp
   *
   * @return string
   */
  protected function squareTimestampToCivi(?string $timestamp): string {
    $time = empty($timestamp) ? FALSE : strtotime($timestamp);
    return date('Y-m-d H:i:s', $time === FALSE ? time() : $time);
  }

  /**
   * Total a Square payment's processing fees, in major currency units.
   *
   * @param array $processingFees
   *   The payment's processing_fee list.
   *
   * @return float|null
   *   NULL when Square has not reported any fee yet.
   */
  protected function sumProcessingFees(array $processingFees): ?float {
    $cents = NULL;
    foreach ($processingFees as $fee) {
      if (isset($fee['amount_money']['amount'])) {
        $cents = ($cents ?? 0) + (int) $fee['amount_money']['amount'];
      }
    }
    return $cents === NULL ? NULL : $cents / 100;
  }

  /**
   * Take a lock that serializes webhook processing for one object.
   *
   * Square delivers invoice.payment_made and payment.updated for the same
   * installment almost simultaneously, processed in separate requests.
   * Locks are always taken in the order payment, then recur, so they
   * cannot deadlock.
   *
   * @param string $key
   *   E.g. 'payment.' . $squarePaymentId, 'recur.' . $recurId.
   *
   * @return \CRM_Core_Lock
   *   Must be released by the caller.
   *
   * @throws \CRM_Core_Payment_SquareRetryableException
   */
  protected function acquireSquareLock(string $key) {
    $lock = new CRM_Core_Lock("worker.square.{$this->gateway->processorId()}.{$key}", 30);
    if (!$lock->acquire()) {
      throw new CRM_Core_Payment_SquareRetryableException("Another request is still processing Square {$key}.");
    }
    return $lock;
  }

  /**
   * Find the recurring contribution linked to a Square subscription on this processor.
   *
   * Scoped to this payment processor (and so to its live/test mode): a
   * webhook for one Square processor never touches another's records.
   *
   * @param string $subscriptionId
   *
   * @return array|null
   */
  protected function findRecurBySubscriptionId(string $subscriptionId): ?array {
    return ContributionRecur::get(FALSE)
      ->addSelect('id', 'contact_id', 'amount', 'currency', 'processor_id', 'contribution_status_id', 'is_email_receipt', 'is_test')
      ->addWhere('processor_id', '=', $subscriptionId)
      ->addWhere('payment_processor_id', '=', $this->gateway->processorId())
      ->addWhere('is_test', '=', $this->gateway->isTestMode())
      ->execute()
      ->first();
  }

  /**
   * The contributions of a recurring series, oldest first, excluding templates.
   *
   * @param int $recurId
   *
   * @return array
   *   Rows as returned by normalizeContribution().
   */
  protected function getRecurContributions(int $recurId): array {
    $contributions = [];
    $rows = Contribution::get(FALSE)
      ->addSelect('id', 'contribution_status_id:name', 'total_amount', 'currency', 'trxn_id', 'invoice_id', 'receipt_date')
      ->addWhere('contribution_recur_id', '=', $recurId)
      ->addWhere('is_test', '=', $this->gateway->isTestMode())
      ->addWhere('is_template', '=', FALSE)
      ->addOrderBy('id', 'ASC')
      ->execute();
    foreach ($rows as $row) {
      $contributions[] = $this->normalizeContribution($row);
    }
    return $contributions;
  }

  /**
   * Find a one-time contribution for a Square payment.
   *
   * @param string $paymentId
   *   Square payment ID, matched against trxn_id.
   * @param string|null $referenceId
   *   Square reference_id, matched against invoice_id (see doOneTimePayment()).
   *
   * @return array|null
   *   As returned by normalizeContribution().
   */
  protected function findContributionForSquarePayment(string $paymentId, ?string $referenceId): ?array {
    $query = Contribution::get(FALSE)
      ->addSelect('id', 'contribution_status_id:name', 'total_amount', 'currency', 'trxn_id', 'invoice_id', 'receipt_date')
      ->addWhere('is_test', '=', $this->gateway->isTestMode())
      // Installments of a recurring series are reconciled by
      // recordSubscriptionInstallment() instead.
      ->addWhere('contribution_recur_id', 'IS EMPTY');
    if ($referenceId !== NULL && $referenceId !== '') {
      $query->addClause('OR', ['trxn_id', '=', $paymentId], ['invoice_id', '=', $referenceId]);
    }
    else {
      $query->addWhere('trxn_id', '=', $paymentId);
    }
    $row = $query->execute()->first();
    return $row ? $this->normalizeContribution($row) : NULL;
  }

  /**
   * Find a payment this processor has recorded with the given transaction ID.
   *
   * @param string $trxnId
   *   Square payment (or refund) ID.
   *
   * @return array|null
   *   With 'id' (financial_trxn ID), 'contribution_id' and 'total_amount'.
   */
  protected function findRecordedPayment(string $trxnId): ?array {
    return Payment::get(FALSE)
      ->addSelect('id', 'contribution_id', 'total_amount')
      ->addWhere('trxn_id', '=', $trxnId)
      ->addWhere('payment_processor_id', '=', $this->gateway->processorId())
      ->execute()
      ->first();
  }

  /**
   * Find a refund recorded in CiviCRM by its Square refund ID.
   *
   * By any processor: a refund recorded from CiviCRM's refund form may
   * carry none.
   *
   * @param string $refundId
   *
   * @return array|null
   *   With 'id' (financial_trxn ID) and 'contribution_id'.
   */
  protected function findRecordedRefund(string $refundId): ?array {
    return Payment::get(FALSE)
      ->addSelect('id', 'contribution_id')
      ->addWhere('trxn_id', '=', $refundId)
      ->addWhere('total_amount', '<', 0)
      ->execute()
      ->first();
  }

  /**
   * Find a contribution in this processor's environment by one of its trxn_ids.
   *
   * @param string $trxnId
   *
   * @return int|null
   */
  protected function findContributionIdByTrxnId(string $trxnId): ?int {
    // Payment.create appends each payment's trxn_id to the contribution's,
    // comma-separated.
    $like = addcslashes($trxnId, '%_\\');
    $contribution = Contribution::get(FALSE)
      ->addSelect('id')
      ->addClause('OR',
        ['trxn_id', '=', $trxnId],
        ['trxn_id', 'LIKE', "{$like},%"],
        ['trxn_id', 'LIKE', "%,{$like}"],
        ['trxn_id', 'LIKE', "%,{$like},%"]
      )
      ->addWhere('is_test', '=', $this->gateway->isTestMode())
      ->execute()
      ->first();
    return !empty($contribution['id']) ? (int) $contribution['id'] : NULL;
  }

  /**
   * Find a payment (or refund) recorded on a contribution, by transaction ID.
   *
   * @param int $contributionId
   * @param string $trxnId
   *
   * @return array|null
   *   With 'id' (financial_trxn ID), 'total_amount' and
   *   'payment_processor_id'.
   */
  protected function findContributionPayment(int $contributionId, string $trxnId): ?array {
    $payments = civicrm_api3('Payment', 'get', [
      'entity_id' => $contributionId,
      'trxn_id' => $trxnId,
      'options' => ['limit' => 1, 'sort' => 'id DESC'],
    ])['values'] ?? [];
    return $payments ? reset($payments) : NULL;
  }

  /**
   * Record a refund as a negative payment.
   *
   * @param array $params
   *   API3 Payment.create params — cancelled_payment_id is only accepted by
   *   the API3 action; API4 Payment::create does not declare it.
   *
   * @throws \CRM_Core_Exception
   */
  protected function recordRefundPayment(array $params): void {
    civicrm_api3('Payment', 'create', $params);
  }

  /**
   * Record a payment on a contribution via CiviCRM's Payment.create.
   *
   * This creates the FinancialTrxn/EntityFinancialTrxn ledger rows and, once
   * the contribution is paid in full, completes it
   * (CRM_Contribute_BAO_Contribution::completeOrder()) as for any other
   * payment processor.
   *
   * @param int $contributionId
   * @param array $values
   *   Keys total_amount, trxn_id, trxn_date, and optionally
   *   payment_instrument_id and fee_amount.
   * @param bool $sendReceipt
   *   Whether completing the contribution emails the contact a receipt.
   *
   * @throws \CRM_Core_Exception
   */
  protected function recordContributionPayment(int $contributionId, array $values, bool $sendReceipt): void {
    $create = Payment::create(FALSE)
      // Payment.create would otherwise always send one.
      ->setNotificationForCompleteOrder($sendReceipt)
      ->addValue('contribution_id', $contributionId)
      ->addValue('total_amount', $values['total_amount'])
      ->addValue('trxn_id', $values['trxn_id'])
      // Payment.create's own 'now' default is not applied.
      ->addValue('trxn_date', $values['trxn_date'] ?? date('Y-m-d H:i:s'))
      ->addValue('payment_processor_id', $this->gateway->processorId());
    if (!empty($values['payment_instrument_id'])) {
      $create->addValue('payment_instrument_id', $values['payment_instrument_id']);
    }
    if (isset($values['fee_amount'])) {
      $create->addValue('fee_amount', $values['fee_amount']);
    }

    // API4 actions are never wrapped in a transaction, and Payment.create
    // saves the payment before completing the contribution. Without this, a
    // failure while completing it would leave a recorded payment on a
    // still-Pending contribution — which every retry would then skip as
    // "already recorded".
    $transaction = new CRM_Core_Transaction();
    try {
      $create->execute();
    }
    catch (\Throwable $e) {
      $transaction->rollback();
      throw $e;
    }
    $transaction->commit();
  }

  /**
   * Create the next contribution of a recurring series.
   *
   * Uses Contribution.repeattransaction so the new contribution copies the
   * series' financial type, line items and financial allocations.
   *
   * @param array $recur
   *   Recurring contribution, as returned by findRecurBySubscriptionId().
   * @param array $values
   *   Keys contribution_status (a status name), and optionally trxn_id,
   *   invoice_id and receive_date.
   *
   * @return array
   *   The new contribution, as returned by normalizeContribution().
   *
   * @throws \CRM_Core_Exception
   */
  protected function repeatRecurContribution(array $recur, array $values): array {
    $params = [
      'contribution_recur_id' => (int) $recur['id'],
      'contribution_status_id' => $values['contribution_status'],
    ];
    foreach (['trxn_id', 'receive_date'] as $field) {
      if (!empty($values[$field])) {
        $params[$field] = $values[$field];
      }
    }
    $contributionId = (int) civicrm_api3('Contribution', 'repeattransaction', $params)['id'];

    if (!empty($values['invoice_id'])) {
      // Contribution.repeattransaction does not accept invoice_id. Recording
      // the Square invoice lets later events for it (e.g. a successful retry
      // after invoice.scheduled_charge_failed) find this contribution.
      $this->updateContribution($contributionId, ['invoice_id' => $values['invoice_id']]);
    }

    $row = Contribution::get(FALSE)
      ->addSelect('id', 'contribution_status_id:name', 'total_amount', 'currency', 'trxn_id', 'invoice_id', 'receipt_date')
      ->addWhere('id', '=', $contributionId)
      ->addWhere('is_test', 'IN', [TRUE, FALSE])
      ->execute()
      ->single();
    return $this->normalizeContribution($row);
  }

  /**
   * The fee already recorded on a contribution.
   *
   * @param int $contributionId
   *
   * @return float
   */
  protected function getContributionFeeAmount(int $contributionId): float {
    $contribution = Contribution::get(FALSE)
      ->addSelect('fee_amount')
      ->addWhere('id', '=', $contributionId)
      ->addWhere('is_test', 'IN', [TRUE, FALSE])
      ->execute()
      ->first();
    return (float) ($contribution['fee_amount'] ?? 0);
  }

  /**
   * Update fields on a contribution.
   *
   * @param int $contributionId
   * @param array $values
   *
   * @throws \CRM_Core_Exception
   */
  protected function updateContribution(int $contributionId, array $values): void {
    Contribution::update(FALSE)
      ->addWhere('id', '=', $contributionId)
      ->setValues($values)
      ->execute();
  }

  /**
   * Set the fee on a recorded payment.
   *
   * @param int $financialTrxnId
   * @param float $fee
   * @param float $totalAmount
   *   The payment's amount, for its net amount.
   *
   * @throws \CRM_Core_Exception
   */
  protected function updatePaymentFee(int $financialTrxnId, float $fee, float $totalAmount): void {
    FinancialTrxn::update(FALSE)
      ->addWhere('id', '=', $financialTrxnId)
      ->addValue('fee_amount', $fee)
      ->addValue('net_amount', $totalAmount - $fee)
      ->execute();
  }

  /**
   * Update fields on a recurring contribution.
   *
   * @param int $recurId
   * @param array $values
   *
   * @throws \CRM_Core_Exception
   */
  protected function updateRecur(int $recurId, array $values): void {
    ContributionRecur::update(FALSE)
      ->addWhere('id', '=', $recurId)
      ->setValues($values)
      ->execute();
  }

  /**
   * Create a contribution.
   *
   * @param array $values
   *
   * @return int
   *   The new contribution's ID.
   *
   * @throws \CRM_Core_Exception
   */
  protected function createContribution(array $values): int {
    return (int) Contribution::create(FALSE)
      ->setValues($values)
      ->execute()
      ->first()['id'];
  }

  /**
   * Normalize an API4 contribution row for the reconciliation logic above.
   *
   * @param array $row
   *
   * @return array
   *   id, status (name), total_amount, currency, trxn_id, invoice_id,
   *   receipt_date.
   */
  protected function normalizeContribution(array $row): array {
    return [
      'id' => (int) $row['id'],
      'status' => $row['contribution_status_id:name'],
      'total_amount' => (float) $row['total_amount'],
      'currency' => $row['currency'],
      'trxn_id' => $row['trxn_id'] ?? NULL,
      'invoice_id' => $row['invoice_id'] ?? NULL,
      'receipt_date' => $row['receipt_date'] ?? NULL,
    ];
  }

  /**
   * Sync a Square refund into CiviCRM.
   *
   * Recorded as a negative payment linked to the refunded payment, as
   * CiviCRM's own Payment.cancel does, so partial refunds are represented
   * and CRM_Financial_BAO_Payment::create() sets the contribution status.
   *
   * @param array $refund
   *   Refund object from a refund.created or refund.updated webhook.
   */
  public function syncRefundFromSquare(array $refund) {
    $paymentId = $refund['payment_id'] ?? NULL;
    $refundId = $refund['id'] ?? NULL;
    $refundStatus = strtoupper(trim($refund['status'] ?? ''));
    CRM_Core_Payment_SquareDebugLogger::log("Square syncRefundFromSquare(): called from SquareIPN for refund_id={$refundId}, payment_id={$paymentId}, status={$refundStatus}.");
    if (!$paymentId || !$refundId) {
      CRM_Core_Payment_SquareDebugLogger::log('Square syncRefundFromSquare(): missing payment_id or refund_id, skipping.');
      return;
    }
    if (in_array($refundStatus, ['REJECTED', 'FAILED'], TRUE)) {
      // doRefund() reports a PENDING refund Completed, so CiviCRM may already
      // have recorded one Square went on to refuse. That is never reversed
      // automatically.
      $recorded = $this->findRecordedRefund($refundId);
      if ($recorded) {
        Civi::log('square')->error("Square refund {$refundId} of payment {$paymentId} was {$refundStatus}, but CiviCRM recorded it as refunded on contribution {$recorded['contribution_id']}; reverse that refund manually.");
      }
      return;
    }
    if (!in_array($refundStatus, ['COMPLETED', 'APPROVED'], TRUE)) {
      // refund.created normally reports PENDING; refund.updated follows once
      // Square completes (or rejects) the refund.
      CRM_Core_Payment_SquareDebugLogger::log("Square syncRefundFromSquare(): refund {$refundId} not yet settled (status={$refundStatus}), skipping until it completes.");
      return;
    }

    $money = $refund['amount_money'] ?? NULL;
    $refundAmount = ($money && isset($money['amount'])) ? ((float) $money['amount'] / 100) : NULL;
    if ($refundAmount === NULL || $refundAmount <= 0) {
      Civi::log()->error("Square syncRefundFromSquare(): refund {$refundId} for payment {$paymentId} has no usable amount, skipping.");
      return;
    }

    // refund.created and refund.updated can both report COMPLETED, in
    // separate concurrent requests.
    $lock = $this->acquireSquareLock('refund.' . $refundId);
    try {
      $this->recordSquareRefund($refund, $refundAmount);
    }
    finally {
      $lock->release();
    }
  }

  /**
   * Record a completed Square refund, once. Body of syncRefundFromSquare().
   *
   * @param array $refund
   *   Refund object from a refund.created or refund.updated webhook.
   * @param float $refundAmount
   *
   * @throws \CRM_Core_Exception
   */
  private function recordSquareRefund(array $refund, float $refundAmount): void {
    $paymentId = $refund['payment_id'];
    $refundId = $refund['id'];

    // The refunded payment, as recorded by this processor.
    $original = $this->findRecordedPayment($paymentId);
    if (!$original) {
      // Payments recorded before payments carried a processor are found by
      // the contribution's own trxn_id — but only if the payment really has
      // no processor: one recorded by another processor is not this one's
      // to refund.
      $contributionId = $this->findContributionIdByTrxnId($paymentId);
      $original = $contributionId ? $this->findContributionPayment($contributionId, $paymentId) : NULL;
      if ($original) {
        $original['contribution_id'] = $contributionId;
      }
      if (!$original || !empty($original['payment_processor_id'])) {
        CRM_Core_Payment_SquareDebugLogger::log("Square syncRefundFromSquare(): no payment of this processor found for Square payment {$paymentId}, skipping refund {$refundId}.");
        return;
      }
    }
    $contributionId = (int) $original['contribution_id'];

    // Idempotency: a replayed webhook (or refund.created and refund.updated
    // both reporting COMPLETED), or a refund CiviCRM already recorded when
    // staff refunded from CiviCRM (see doRefund()), must not refund twice.
    if ($this->findContributionPayment($contributionId, $refundId)) {
      CRM_Core_Payment_SquareDebugLogger::log("Square syncRefundFromSquare(): refund {$refundId} already recorded for contribution {$contributionId}, skipping.");
      return;
    }

    $refundParams = [
      'contribution_id' => $contributionId,
      'total_amount' => -$refundAmount,
      'trxn_id' => $refundId,
      'trxn_date' => $this->squareTimestampToCivi($refund['updated_at'] ?? $refund['created_at'] ?? NULL),
      'payment_processor_id' => $this->gateway->processorId(),
      'is_send_contribution_notification' => 0,
    ];
    // A full refund is linked to the refunded payment, as CiviCRM's own
    // Payment.cancel does. Not a partial one: Payment.create reverses every
    // allocation of the cancelled payment in full, whatever the refund
    // amount. A partial refund is allocated across the line items instead.
    $isFullRefund = (int) round($refundAmount * 100) === (int) round((float) $original['total_amount'] * 100);
    if ($isFullRefund) {
      $refundParams['cancelled_payment_id'] = (int) $original['id'];
    }
    $this->recordRefundPayment($refundParams);

    CRM_Core_Payment_SquareDebugLogger::log("Square syncRefundFromSquare(): recorded refund {$refundId} ({$refundAmount}) against contribution {$contributionId}" . ($isFullRefund ? ", cancelling payment {$original['id']}." : '.'));
  }

  /**
   * Sync a paid Square subscription invoice into CiviCRM.
   *
   * Not currently called by any webhook route (invoice.payment_made is
   * handled by handleInvoicePaymentCreated() instead) but kept as public
   * API surface, so it delegates to the same ledger-safe path.
   *
   * @param array $invoice
   */
  public function syncInvoiceFromSquare(array $invoice) {
    $this->handleInvoicePaymentCreated(['data' => ['object' => ['invoice' => $invoice]]]);
  }

  /**
   * Sync a Square subscription with the corresponding CiviCRM recurring contribution.
   *
   * Called for subscription.created and subscription.updated webhooks.
   * Square has no subscription.canceled event: a cancellation arrives as a
   * subscription.updated whose status is CANCELED.
   *
   * @param string $squareSubscriptionId
   *   The subscription ID from Square.
   *
   * @throws \CRM_Core_Exception
   */
  public function syncSubscriptionFromSquare(string $squareSubscriptionId) {
    CRM_Core_Payment_SquareDebugLogger::log("Square syncSubscriptionFromSquare(): called from SquareIPN for subscription_id={$squareSubscriptionId}.");
    if (empty($squareSubscriptionId)) {
      throw new CRM_Core_Exception('Missing Square subscription ID for sync.');
    }

    // 1. Look up the subscription in Square
    $response = $this->gateway->call(fn (SquareClient $client) => $client->subscriptions->get(
      new GetSubscriptionsRequest(['subscriptionId' => $squareSubscriptionId])
    ));
    $sub = $response->getSubscription();

    if (empty($sub)) {
      throw new CRM_Core_Exception("Square subscription {$squareSubscriptionId} not found.");
    }

    // 2. Find local CiviCRM recurring contribution
    $recur = $this->findRecurBySubscriptionId($squareSubscriptionId);
    if (empty($recur)) {
      // No such recurring record exists — log and stop.
      CRM_Core_Payment_SquareDebugLogger::log("Square sync: No local contribution_recur record found for subscription {$squareSubscriptionId}");
      return;
    }

    // 3. Apply the status and amount. extractSubscriptionAmount() expects the
    // REST JSON shape; jsonSerialize() gives us that from the SDK object
    // without duplicating the extraction logic.
    $this->applySubscriptionToRecur($recur, $sub->getStatus() ?? 'UNKNOWN', $this->extractSubscriptionAmount($sub->jsonSerialize()), $sub->getCanceledDate());
  }

  /**
   * Apply a Square subscription's status and amount to its recurring contribution.
   *
   * @param array $recur
   *   Recurring contribution, as returned by findRecurBySubscriptionId().
   * @param string $squareStatus
   * @param float|null $amount
   *   The subscription's price override, if any.
   * @param string|null $canceledDate
   *   The subscription's canceled_date (YYYY-MM-DD), if any.
   */
  protected function applySubscriptionToRecur(array $recur, string $squareStatus, ?float $amount, ?string $canceledDate = NULL): void {
    $recurId = (int) $recur['id'];
    $currentStatus = (int) $recur['contribution_status_id'];
    CRM_Core_Payment_SquareDebugLogger::log("Square: applying subscription status {$squareStatus} to contribution_recur {$recurId} (current status {$currentStatus}).");

    $updates = [];
    if (!empty($amount) && (float) $amount !== (float) $recur['amount']) {
      $updates['amount'] = (float) $amount;
    }

    $mappedStatus = CRM_Square_Status::mapSubscriptionStatus($squareStatus);
    if ($mappedStatus !== NULL && $mappedStatus !== $currentStatus) {
      if ($this->isRecurStatusChangeAllowed($currentStatus, $mappedStatus)) {
        $updates['contribution_status_id'] = $mappedStatus;
        if ($mappedStatus === CRM_Square_Status::recurStatusId('Cancelled')) {
          $updates['cancel_date'] = $canceledDate ? date('Y-m-d 00:00:00', strtotime($canceledDate)) : date('Y-m-d H:i:s');
        }
      }
      else {
        CRM_Core_Payment_SquareDebugLogger::log("Square: not applying Square status {$squareStatus} to contribution_recur {$recurId}; its payment-driven status {$currentStatus} is kept.");
      }
    }

    if (empty($updates)) {
      CRM_Core_Payment_SquareDebugLogger::log("Square: contribution_recur {$recurId} already up to date.");
      return;
    }

    $this->updateRecur($recurId, $updates);
    CRM_Core_Payment_SquareDebugLogger::log("Square sync: Updated recurring contribution {$recurId}: " . json_encode($updates));
  }

  /**
   * Whether a status Square reports for a subscription may be applied to its recurring contribution.
   *
   * Pending and In Progress say whether the series has been paid for, which
   * CiviCRM records itself: Payment.create moves a recurring contribution
   * from Pending to In Progress when its first payment is recorded (see
   * recordSubscriptionInstallment()). A Square subscription's status says
   * nothing about payment — it is ACTIVE from the moment billing starts,
   * PENDING before its start date — and its webhooks can arrive after the
   * payment's. So Square never moves a recur back to Pending, nor to In
   * Progress before its first payment is recorded — or after it was
   * Cancelled or Completed (Square keeps reporting a cancelled subscription
   * ACTIVE until its cancellation date). Cancelled, Completed and Failed
   * always apply.
   *
   * @param int $currentStatusId
   * @param int $newStatusId
   *
   * @return bool
   */
  protected function isRecurStatusChangeAllowed(int $currentStatusId, int $newStatusId): bool {
    $pending = CRM_Square_Status::recurStatusId('Pending');
    if ($newStatusId === $pending) {
      return FALSE;
    }
    if ($newStatusId === CRM_Square_Status::recurStatusId('In Progress')) {
      return !in_array($currentStatusId, [
        $pending,
        CRM_Square_Status::recurStatusId('Cancelled'),
        CRM_Square_Status::recurStatusId('Completed'),
      ], TRUE);
    }
    return TRUE;
  }

  /**
   * Extract override amount from Square subscription.
   *
   * @param array $subscription
   *
   * @return float|null
   */
  protected function extractSubscriptionAmount(array $subscription) {
    if (!empty($subscription['price_override_money']['amount'])) {
      return ((float) $subscription['price_override_money']['amount']) / 100;
    }

    // If no override, fall back to catalog plan pricing (unavailable via subscription API alone).
    return NULL;
  }

  /**
   * Process Square invoice.payment_made webhook event.
   *
   * The preferred way a subscription installment is recorded: unlike a
   * payment.updated payload, the invoice names its subscription. The
   * installment itself is recorded by recordSubscriptionInstallment(),
   * which payment.updated uses too, so whichever arrives first records it
   * and the other is a no-op.
   *
   * @param array $payload
   *   Full decoded JSON from Square webhook.
   *
   * @throws \CRM_Core_Exception
   */
  public function handleInvoicePaymentCreated(array $payload) {
    CRM_Core_Payment_SquareDebugLogger::log('Square handleInvoicePaymentCreated(): called from SquareIPN for invoice.payment_made.');
    $invoice = $payload['data']['object']['invoice'] ?? NULL;
    $invoiceId = $invoice['id'] ?? NULL;
    if (!$invoiceId) {
      CRM_Core_Payment_SquareDebugLogger::log('Square webhook: invoice.payment_made missing invoice object or ID.');
      return;
    }

    $subscriptionId = $invoice['subscription_id'] ?? NULL;
    if (!$subscriptionId) {
      CRM_Core_Payment_SquareDebugLogger::log("Square webhook: invoice {$invoiceId} has no subscription_id, skipping.");
      return;
    }

    $invoiceStatus = strtoupper((string) ($invoice['status'] ?? ''));
    if ($invoiceStatus !== 'PAID') {
      CRM_Core_Payment_SquareDebugLogger::log("Square webhook: invoice {$invoiceId} is {$invoiceStatus}; nothing is recorded until it is paid in full.");
      return;
    }

    $recur = $this->findRecurBySubscriptionId($subscriptionId);
    if (!$recur) {
      $this->handleUnknownSubscription($subscriptionId, $invoice['updated_at'] ?? $invoice['created_at'] ?? NULL, "invoice {$invoiceId}");
      return;
    }

    $this->recordSubscriptionInstallment($recur, $this->installmentFromInvoice($invoice));
  }

  /**
   * Sync a Square invoice.scheduled_charge_failed webhook event.
   *
   * @param array $invoice
   *   Invoice object from the webhook payload.
   *
   * @throws \CRM_Core_Exception
   */
  public function syncInvoicePaymentFailedFromSquare(array $invoice): void {
    $invoiceId = $invoice['id'] ?? NULL;
    $subscriptionId = $invoice['subscription_id'] ?? NULL;
    if (!$invoiceId || !$subscriptionId) {
      CRM_Core_Payment_SquareDebugLogger::log('Square: invoice.scheduled_charge_failed missing invoice ID or subscription_id, skipping.');
      return;
    }

    $recur = $this->findRecurBySubscriptionId($subscriptionId);
    if (!$recur) {
      $this->handleUnknownSubscription($subscriptionId, $invoice['updated_at'] ?? $invoice['created_at'] ?? NULL, "invoice {$invoiceId}");
      return;
    }

    // The same lock as recordSubscriptionInstallment(): a failure and a
    // success for the series must not decide concurrently.
    $lock = $this->acquireSquareLock('recur.' . $recur['id']);
    try {
      $contributions = $this->getRecurContributions((int) $recur['id']);

      foreach ($contributions as $contribution) {
        if (($contribution['invoice_id'] ?? NULL) === $invoiceId) {
          if ($contribution['status'] === 'Pending') {
            $this->updateContribution($contribution['id'], ['contribution_status_id' => CRM_Square_Status::contributionStatusId('Failed')]);
            CRM_Core_Payment_SquareDebugLogger::log("Square: Marked contribution {$contribution['id']} as Failed for invoice {$invoiceId}.");
          }
          return;
        }
      }

      $signup = $this->findSignupContribution($contributions, $recur);
      if ($signup) {
        // Square could not charge the first installment. It keeps the
        // invoice open for the customer to pay, so the checkout's
        // contribution stays Pending — and is completed if Square collects
        // it later — rather than gaining a separate Failed duplicate.
        Civi::log()->warning("Square: the first payment of recurring contribution {$recur['id']} (Square invoice {$invoiceId}) failed; contribution {$signup['id']} remains Pending.");
        return;
      }

      $failed = $this->repeatRecurContribution($recur, [
        'contribution_status' => 'Failed',
        'invoice_id' => $invoiceId,
        'receive_date' => $this->squareTimestampToCivi($invoice['updated_at'] ?? NULL),
      ]);
      CRM_Core_Payment_SquareDebugLogger::log("Square: Created Failed contribution {$failed['id']} for invoice {$invoiceId}.");
    }
    finally {
      $lock->release();
    }
  }

  /**
   * Find the contact a Square payment made outside CiviCRM belongs to.
   *
   * By the payment's Square customer, if this processor has mapped it to a
   * contact; otherwise by the receipt email, if exactly one contact has it
   * as their primary email (family members often share one). Neither the
   * customer's nor the payment's reference_id is trusted: other Square
   * products and integrations set those to their own values.
   *
   * @param array $payment
   *   Payment object from Square API.
   *
   * @return int|null
   *   CiviCRM contact ID or NULL if not found.
   */
  protected function findContactIdForPayment(array $payment) {
    $customerId = $payment['customer_id'] ?? NULL;
    if ($customerId) {
      $contactId = $this->findContactIdBySquareCustomer((string) $customerId);
      if ($contactId) {
        return $contactId;
      }
    }

    $receiptEmail = trim((string) ($payment['buyer_email_address'] ?? ''));
    if ($receiptEmail !== '') {
      $contactIds = $this->findContactIdsByEmail($receiptEmail);
      if (count($contactIds) === 1) {
        return $contactIds[0];
      }
      if (count($contactIds) > 1) {
        CRM_Core_Payment_SquareDebugLogger::log('Square findContactIdForPayment(): ' . count($contactIds) . " contacts share the email of payment {$payment['id']}; not choosing one.");
      }
    }

    return NULL;
  }

  /**
   * The contact this processor has mapped a Square customer to.
   *
   * @param string $customerId
   *
   * @return int|null
   */
  protected function findContactIdBySquareCustomer(string $customerId): ?int {
    $contactId = CRM_Core_DAO::singleValueQuery(
      'SELECT contact_id FROM square_customer_map WHERE square_customer_id = %1 AND payment_processor_id = %2',
      [1 => [$customerId, 'String'], 2 => [$this->gateway->processorId(), 'Integer']]
    );
    return $contactId ? (int) $contactId : NULL;
  }

  /**
   * Contacts (not deleted) whose primary email is the given one.
   *
   * @param string $email
   *
   * @return int[]
   *   At most two: enough to tell whether it is unique.
   */
  protected function findContactIdsByEmail(string $email): array {
    return array_map('intval', Contact::get(FALSE)
      ->addSelect('id')
      ->addWhere('email_primary.email', '=', $email)
      ->setLimit(2)
      ->execute()
      ->column('id'));
  }

  /**
   * Whether Square payments made outside CiviCRM are imported as contributions.
   *
   * @return bool
   */
  protected function isImportingExternalPayments(): bool {
    return (bool) Civi::settings()->get('square_import_external_payments');
  }

}
