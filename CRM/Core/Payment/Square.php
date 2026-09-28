<?php

use CRM_Square_ExtensionUtil as E;
use Civi\Api4\Contribution;
use Civi\Api4\Contact;
use Civi\Api4\ContributionRecur;
use Civi\Api4\Payment;
use Civi\Api4\PaymentToken;
use Civi\Payment\Exception\PaymentProcessorException;
use Civi\Payment\PropertyBag;
use Square\SquareClient;
use Square\Exceptions\SquareApiException;
use Square\Exceptions\SquareException;
use Square\Types\Money;
use Square\Types\Address;
use Square\Types\Card;
use Square\Types\CustomerQuery;
use Square\Types\CustomerFilter;
use Square\Types\CustomerTextFilter;
use Square\Types\SubscriptionSource;
use Square\Types\SubscriptionPhase;
use Square\Types\SubscriptionPricing;
use Square\Types\CatalogObject;
use Square\Types\CatalogObjectBatch;
use Square\Types\CatalogObjectSubscriptionPlan;
use Square\Types\CatalogObjectSubscriptionPlanVariation;
use Square\Types\CatalogSubscriptionPlan;
use Square\Types\CatalogSubscriptionPlanVariation;
use Square\Types\InvoiceFilter;
use Square\Types\InvoiceQuery;
use Square\Types\InvoiceSort;
use Square\Customers\Requests\CreateCustomerRequest;
use Square\Customers\Requests\UpdateCustomerRequest;
use Square\Customers\Requests\GetCustomersRequest;
use Square\Customers\Requests\SearchCustomersRequest;
use Square\Cards\Requests\CreateCardRequest;
use Square\Payments\Requests\CreatePaymentRequest;
use Square\Subscriptions\Requests\CreateSubscriptionRequest;
use Square\Subscriptions\Requests\GetSubscriptionsRequest;
use Square\Subscriptions\Requests\CancelSubscriptionsRequest;
use Square\Refunds\Requests\RefundPaymentRequest;
use Square\Catalog\Requests\BatchUpsertCatalogObjectsRequest;
use Square\Invoices\Requests\SearchInvoicesRequest;
use Square\Orders\Requests\GetOrdersRequest;

require_once E::path() . '/vendor/autoload.php';
// Explicit require (rather than relying on CiviCRM's CRM_ classloader,
// unavailable in the mocked-CiviCRM PHPUnit suite — see tests/phpunit/
// bootstrap.php) since callSquare()/squareApiError() below construct this
// class directly.
require_once __DIR__ . '/SquareRetryableException.php';
/**
 * Square Payment Processor for CiviCRM.
 *
 * This processor supports:
 *  - One-off (non-recurring) card payments via Square Payments API
 *  - Recurring contributions via Square Subscriptions API.
 *
 * Card details are never handled by CiviCRM directly. Instead, the
 * Square Web Payments SDK is used in the browser to tokenize the card
 * and pass a token/nonce back to this class via $params.
 */
class CRM_Core_Payment_Square extends CRM_Core_Payment {

  /**
   * Payment-processor instance configuration.
   *
   * @var array
   */
  protected $_paymentProcessor = [];

  /**
   * Active CiviCRM component.
   *
   * @var string
   *   Component name (e.g. 'contribute' or 'event'), set by doPayment().
   *   Declared explicitly to avoid PHP 8.2's deprecated dynamic-property
   *   creation notice — CiviCRM core's CRM_Core_Payment declares this too,
   *   but not every context this class runs in guarantees that.
   */
  protected $_component = 'contribute';

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
   * Square-supported cadence definitions.
   */
  protected const SQUARE_CADENCES = [
    'DAILY' => [
      'label' => 'Daily',
      'unit' => 'day',
      'step' => 1,
    ],
    'WEEKLY' => [
      'label' => 'Weekly',
      'unit' => 'week',
      'step' => 1,
    ],
    'EVERY_TWO_WEEKS' => [
      'label' => 'Every 2 Weeks',
      'unit' => 'week',
      'step' => 2,
    ],
    'MONTHLY' => [
      'label' => 'Monthly',
      'unit' => 'month',
      'step' => 1,
    ],
    'EVERY_TWO_MONTHS' => [
      'label' => 'Every 2 Months',
      'unit' => 'month',
      'step' => 2,
    ],
    'QUARTERLY' => [
      'label' => 'Quarterly',
      'unit' => 'month',
      'step' => 3,
    ],
    'EVERY_SIX_MONTHS' => [
      'label' => 'Every 6 Months',
      'unit' => 'month',
      'step' => 6,
    ],
    'ANNUAL' => [
      'label' => 'Annual',
      'unit' => 'year',
      'step' => 1,
    ],
  ];

  /**
   * Constructor.
   *
   * @param string $mode
   *   Test or live.
   * @param array $paymentProcessor
   *   Row from civicrm_payment_processor.
   */
  public function __construct($mode, array &$paymentProcessor) {
    // Store processor config.
    $this->_paymentProcessor = $paymentProcessor;
  }

  /**
   * Whether this processor is in test/sandbox mode.
   *
   * @return bool
   */
  protected function isTestMode() {
    return !empty($this->_paymentProcessor['is_test']);
  }

  /**
   * Inject Square assets and card-container HTML into the billing block.
   *
   * This method is called by CiviCRM for ALL form types that render the billing
   * block, including:
   *  - Native contribution pages / event registration
   *  - Drupal Webform AJAX billing block requests (CRM_Core_Payment_Form)
   *  - Backend contribution/event forms.
   *
   * We use CRM_Core_Region::instance('billing-block')->add() rather than
   * \Civi::resources()->addScriptFile() because the latter does NOT work for
   * AJAX billing block responses (e.g. Drupal webforms).
   *
   * @param \CRM_Core_Form $form
   */
  public function buildForm(&$form) {
    $isSandbox = FALSE;
    if ($this->_paymentProcessor['is_test']) {
      $isSandbox = TRUE;
    }

    $sdkUrl = $isSandbox
      ? 'https://sandbox.web.squarecdn.com/v1/square.js'
      : 'https://web.squarecdn.com/v1/square.js';

    $jsVars = [
      'id' => (int) ($this->_paymentProcessor['id'] ?? 0),
      'applicationId' => $this->_paymentProcessor['user_name'] ?? '',
      'locationId' => $this->_paymentProcessor['signature'] ?? ($this->_paymentProcessor['password'] ?? ''),
      'isSandbox' => (bool) $isSandbox,
    ];

    // Add hidden field for the payment token.
    if (!$form->elementExists('square_payment_token')) {
      $form->add('hidden', 'square_payment_token', '', ['id' => 'square_payment_token']);
    }

    // Square Web Payments SDK (loaded before our JS).
    CRM_Core_Region::instance('billing-block')->add([
      'scriptUrl' => $sdkUrl,
      'weight' => -1,
    ]);

    // Our integration JS (loaded last so CRM.squarePayment utilities are ready).
    CRM_Core_Region::instance('billing-block')->add([
      'scriptUrl' => E::url('js/square.js'),
      'weight' => 100,
    ]);

    // Publish settings to CRM.vars.orgUschessSquare (works for normal page load).
    CRM_Core_Resources::singleton()->addSetting(['orgUschessSquare' => $jsVars]);

    // Pass vars to Smarty so the template can emit an inline <script> fallback
    // for Drupal webforms where addSetting() responses may not be processed.
    $form->assign('squareJSVarsJson', json_encode($jsVars, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT));

    // Billing block HTML: card container + error element + inline JS fallback.
    CRM_Core_Region::instance('billing-block')->add([
      'template' => E::path('templates/CRM/Core/Payment/Square/Card.tpl'),
      'weight' => -1,
    ]);

    // Enable JS validation so submission only happens after fields are valid.
    $form->assign('isJsValidate', TRUE);

    // We augment the standard billing block rather than replace it, so form
    // building must continue as normal (CRM_Core_Payment::buildForm()'s
    // contract: FALSE = "continue normal form building").
    return FALSE;
  }

  /**
   * Build a SquareClient configured for this processor (2025 SDK style).
   *
   * Uses the password field as access token and honours is_test.
   *
   * @return \Square\SquareClient
   *
   * @throws \CRM_Core_Exception
   */
  protected function buildSquareClient(): SquareClient {
    return new SquareClient(
      token: $this->getAccessToken(),
      options: ['baseUrl' => $this->getApiBaseUrl()],
    );
  }

  /**
   * Get the Square access token from processor config.
   *
   * @return string
   *
   * @throws \CRM_Core_Exception
   */
  protected function getAccessToken() {

    return trim($this->_paymentProcessor['password'] ?? '');
  }

  /**
   * Get the Square Location ID from processor config.
   *
   * @return string
   *
   * @throws \CRM_Core_Exception
   */
  protected function getLocationId() {
    $loc = trim($this->_paymentProcessor['signature'] ?? '');
    if (empty($loc)) {
      throw new CRM_Core_Exception('Square location ID is not configured on this payment processor.');
    }
    return $loc;
  }

  /**
   * Base URL for Square API, depending on mode and config.
   *
   * @return string
   */
  protected function getApiBaseUrl() {
    // Allow overriding via processor config if provided.
    if (!empty($this->_paymentProcessor['url_api'])) {
      return rtrim($this->_paymentProcessor['url_api'], '/');
    }

    // Fallback: use sensible defaults based on test/live.
    if ($this->isTestMode()) {
      return 'https://connect.squareupsandbox.com';
    }

    return 'https://connect.squareup.com';
  }

  /**
   * Build a retry-stable idempotency key unique to each processor and environment.
   */
  protected function idempotencyKey(string $operation, string $reference): string {
    $processorId = (string) ($this->_paymentProcessor['id'] ?? '0');
    $environment = $this->isTestMode() ? 'test' : 'live';
    return substr('civi-' . $operation . '-' . hash('sha256', implode(':', [
      $processorId,
      $environment,
      $reference,
    ])), 0, 45);
  }

  /**
   * Resolve a contribution_status_id by name without relying on installation-specific IDs.
   */
  protected function contributionStatusId(string $name): int {
    return $this->pseudoConstantId('contribution_status_id', $name);
  }

  /**
   * Resolve a payment_instrument_id by name without relying on installation-specific IDs.
   */
  protected function paymentInstrumentId(string $name): int {
    return $this->pseudoConstantId('payment_instrument_id', $name);
  }

  /**
   * Resolve a financial_type_id by name without relying on installation-specific IDs.
   */
  protected function financialTypeId(string $name): int {
    return $this->pseudoConstantId('financial_type_id', $name);
  }

  /**
   * Resolve a Contribution-entity pseudoconstant value by name.
   *
   * Replaces the deprecated CRM_Contribute_PseudoConstant::contributionStatus()
   * (and avoids hard-coding installation-specific numeric IDs for statuses,
   * payment instruments, and financial types).
   */
  protected function pseudoConstantId(string $field, string $name): int {
    $id = \CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', $field, $name);
    if ($id === FALSE || $id === NULL) {
      throw new \CRM_Core_Exception("CiviCRM {$field} '{$name}' is unavailable.");
    }
    return (int) $id;
  }

  /**
   * ID of this payment processor (civicrm_payment_processor.id).
   */
  protected function processorId(): int {
    return (int) ($this->_paymentProcessor['id'] ?? 0);
  }

  /**
   * Validate Square configuration by making a real SDK call.
   *
   * @return string|null
   */
  public function checkConfig() {
    $missing = [];
    foreach ([
      'user_name' => 'Application ID',
      'password' => 'Access Token',
      'signature' => 'Location ID',
      'subject' => 'Webhook Signature Key',
    ] as $field => $label) {
      if (trim((string) ($this->_paymentProcessor[$field] ?? '')) === '') {
        $missing[] = $label;
      }
    }
    return $missing ? ts('Square configuration is missing: %1.', [1 => implode(', ', $missing)]) : NULL;
  }

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
   * fee_amount makes CiviCRM record the fee transaction.
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
    if ($existing['status'] === 'Pending' && $this->mapPaymentStatus($status) === $this->contributionStatusId('Failed')) {
      $this->updateContribution($contributionId, ['contribution_status_id' => $this->contributionStatusId('Failed')]);
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
      'payment_instrument_id' => $this->mapPaymentInstrument($payment['source_type'] ?? NULL),
      'fee_amount' => $this->sumProcessingFees($payment['processing_fee'] ?? []),
    ], FALSE);
    CRM_Core_Payment_SquareDebugLogger::log("Square syncPaymentFromSquare(): completed contribution {$contributionId} for payment {$paymentId} via Payment.create.");
  }

  /**
   * Import a completed Square payment taken outside CiviCRM as a new contribution.
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
        ->addWhere('payment_processor_id', '=', $this->processorId())
        ->addWhere('is_test', '=', $this->isTestMode())
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
    $financialTypeId = $refRecurContribution['financial_type_id'] ?? $this->financialTypeId('Donation');
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
      'contribution_status_id' => $this->contributionStatusId('Pending'),
      'trxn_id' => $paymentId,
      'is_test' => $this->isTestMode(),
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
      'payment_instrument_id' => $this->mapPaymentInstrument($payment['source_type'] ?? NULL),
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
    $locationId = $payment['location_id'] ?? $this->getLocationId();

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
      $response = $this->callSquare(fn (SquareClient $client) => $client->invoices->search($request));
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
      'payment_instrument_id' => $this->mapPaymentInstrument($payment['source_type'] ?? NULL),
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
      'payment_instrument_id' => $this->mapPaymentInstrument($tender['type'] ?? NULL),
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
    $order = $this->callSquare(fn (SquareClient $client) => $client->orders->get(
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
      $this->updateContribution((int) $contribution['id'], ['contribution_status_id' => $this->contributionStatusId('Pending')]);
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
    // Already created for this Square invoice (e.g. by invoice.payment_failed
    // for an earlier attempt).
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
      throw new CRM_Core_Payment_SquareRetryableException("No recurring contribution on payment processor {$this->processorId()} is linked to Square subscription {$subscriptionId} ({$context}) yet; retrying in case checkout is still saving it.");
    }
    CRM_Core_Payment_SquareDebugLogger::log("Square: subscription {$subscriptionId} ({$context}) is not linked to a recurring contribution on payment processor {$this->processorId()}; ignoring.");
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
    $lock = new CRM_Core_Lock("worker.square.{$this->processorId()}.{$key}", 30);
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
      ->addWhere('payment_processor_id', '=', $this->processorId())
      ->addWhere('is_test', '=', $this->isTestMode())
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
      ->addWhere('is_test', '=', $this->isTestMode())
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
      ->addWhere('is_test', '=', $this->isTestMode())
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
      ->addWhere('payment_processor_id', '=', $this->processorId())
      ->execute()
      ->first();
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
      ->addValue('payment_processor_id', $this->processorId());
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
      // after invoice.payment_failed) find this contribution.
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

    // Find the contribution by the refunded payment, as recorded by this
    // processor — falling back to the contribution's own trxn_id for
    // payments recorded before payments carried a processor.
    $original = $this->findRecordedPayment($paymentId);
    $contributionId = !empty($original['contribution_id']) ? (int) $original['contribution_id'] : NULL;
    if (!$contributionId) {
      $contribution = Contribution::get(FALSE)
        ->addSelect('id')
        ->addWhere('trxn_id', '=', $paymentId)
        ->addWhere('is_test', '=', $this->isTestMode())
        ->execute()
        ->first();
      $contributionId = !empty($contribution['id']) ? (int) $contribution['id'] : NULL;
    }
    if (!$contributionId) {
      CRM_Core_Payment_SquareDebugLogger::log("Square syncRefundFromSquare(): no contribution found for payment {$paymentId}, skipping.");
      return;
    }

    // Idempotency: a replayed webhook (or refund.created and refund.updated
    // both reporting COMPLETED), or a refund CiviCRM already recorded when
    // staff refunded from CiviCRM (see doRefund()), must not refund twice.
    $alreadyRecorded = civicrm_api3('Payment', 'get', [
      'entity_id' => $contributionId,
      'trxn_id' => $refundId,
      'options' => ['limit' => 1],
    ])['count'] ?? 0;
    if (!empty($alreadyRecorded)) {
      CRM_Core_Payment_SquareDebugLogger::log("Square syncRefundFromSquare(): refund {$refundId} already recorded for contribution {$contributionId}, skipping.");
      return;
    }

    if (empty($original['id'])) {
      $originalPayments = civicrm_api3('Payment', 'get', [
        'entity_id' => $contributionId,
        'trxn_id' => $paymentId,
        'options' => ['limit' => 1, 'sort' => 'id DESC'],
      ])['values'] ?? [];
      $original = $originalPayments ? reset($originalPayments) : NULL;
    }

    $refundParams = [
      'contribution_id' => $contributionId,
      'total_amount' => -$refundAmount,
      'trxn_id' => $refundId,
      'trxn_date' => $this->squareTimestampToCivi($refund['updated_at'] ?? $refund['created_at'] ?? NULL),
      'payment_processor_id' => $this->processorId(),
      'is_send_contribution_notification' => 0,
    ];
    // A full refund is linked to the refunded payment, as CiviCRM's own
    // Payment.cancel does. Not a partial one: Payment.create reverses every
    // allocation of the cancelled payment in full, whatever the refund
    // amount. A partial refund is allocated across the line items instead.
    // (cancelled_payment_id is only accepted by the API3 action.)
    $isFullRefund = !empty($original['id']) && (int) round($refundAmount * 100) === (int) round((float) $original['total_amount'] * 100);
    if ($isFullRefund) {
      $refundParams['cancelled_payment_id'] = (int) $original['id'];
    }
    civicrm_api3('Payment', 'create', $refundParams);

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
   * This is used when Square sends a webhook (subscription.updated or subscription.canceled)
   * AND also may be triggered manually by scheduled jobs.
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
    $response = $this->callSquare(fn (SquareClient $client) => $client->subscriptions->get(
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
    $this->applySubscriptionToRecur($recur, $sub->getStatus() ?? 'UNKNOWN', $this->extractSubscriptionAmount($sub->jsonSerialize()));
  }

  /**
   * Apply a Square subscription's status and amount to its recurring contribution.
   *
   * @param array $recur
   *   Recurring contribution, as returned by findRecurBySubscriptionId().
   * @param string $squareStatus
   * @param float|null $amount
   *   The subscription's price override, if any.
   */
  protected function applySubscriptionToRecur(array $recur, string $squareStatus, ?float $amount): void {
    $recurId = (int) $recur['id'];
    $currentStatus = (int) $recur['contribution_status_id'];
    CRM_Core_Payment_SquareDebugLogger::log("Square: applying subscription status {$squareStatus} to contribution_recur {$recurId} (current status {$currentStatus}).");

    $updates = [];
    if (!empty($amount) && (float) $amount !== (float) $recur['amount']) {
      $updates['amount'] = (float) $amount;
    }

    $mappedStatus = $this->mapSquareSubscriptionStatusToCivi($squareStatus);
    if ($mappedStatus !== NULL && $mappedStatus !== $currentStatus) {
      if ($this->isRecurStatusChangeAllowed($currentStatus, $mappedStatus)) {
        $updates['contribution_status_id'] = $mappedStatus;
      }
      else {
        CRM_Core_Payment_SquareDebugLogger::log("Square: not applying Square status {$squareStatus} to contribution_recur {$recurId}; its payment-driven status {$currentStatus} is kept.");
      }
    }

    if (empty($updates)) {
      CRM_Core_Payment_SquareDebugLogger::log("Square: contribution_recur {$recurId} already up to date.");
      return;
    }

    ContributionRecur::update(FALSE)
      ->addWhere('id', '=', $recurId)
      ->setValues($updates)
      ->execute();
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
    $pending = $this->contributionStatusId('Pending');
    if ($newStatusId === $pending) {
      return FALSE;
    }
    if ($newStatusId === $this->contributionStatusId('In Progress')) {
      return !in_array($currentStatusId, [
        $pending,
        $this->contributionStatusId('Cancelled'),
        $this->contributionStatusId('Completed'),
      ], TRUE);
    }
    return TRUE;
  }

  /**
   * Map Square subscription statuses to CiviCRM contribution_status_id.
   *
   * @param string $squareStatus
   *
   * @return int|null
   */
  protected function mapSquareSubscriptionStatusToCivi($squareStatus) {
    $squareStatus = strtoupper(trim($squareStatus));

    // Square subscription statuses per the Subscriptions API: PENDING,
    // ACTIVE, CANCELED, DEACTIVATED, PAUSED, and (API versions
    // 2025-09-24+) COMPLETED. There is no SUSPENDED status. See
    // isRecurStatusChangeAllowed() for when a mapped status is applied.
    switch ($squareStatus) {
      case 'PENDING':
      case 'PAUSED':
        return $this->contributionStatusId('Pending');

      case 'ACTIVE':
        return $this->contributionStatusId('In Progress');

      case 'COMPLETED':
        return $this->contributionStatusId('Completed');

      case 'CANCELED':
        return $this->contributionStatusId('Cancelled');

      case 'DEACTIVATED':
        return $this->contributionStatusId('Failed');
    }

    // If unknown, don't change local status.
    return NULL;
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
   * Determine financial_type_id for contributions created by Square.
   *
   * Priority:
   *  1. Contribution params (financialTypeID / financial_type_id).
   *  2. Recurring template on contribution_recur.
   *  3. "Donation", resolved by name.
   *
   * @param array $params
   *
   * @return int
   */
  protected function getFinancialTypeId(array $params) {
    // 1. Direct param from contribution form
    if (!empty($params['financialTypeID'])) {
      return (int) $params['financialTypeID'];
    }
    if (!empty($params['financial_type_id'])) {
      return (int) $params['financial_type_id'];
    }

    // 2. Check recurring template if recurID provided
    if (!empty($params['contributionRecurID'])) {
      $recur = ContributionRecur::get(FALSE)
        ->addWhere('id', '=', (int) $params['contributionRecurID'])
        ->addWhere('is_test', 'IN', [TRUE, FALSE])
        ->addSelect('financial_type_id')
        ->execute()
        ->first();

      if (!empty($recur['financial_type_id'])) {
        return (int) $recur['financial_type_id'];
      }
    }

    // 3. Fallback to Donation, resolved by name — never a hard-coded ID,
    // which is not stable across CiviCRM installs.
    CRM_Core_Payment_SquareDebugLogger::log('Square getFinancialTypeId(): no financial type resolved from params or recurring template; falling back to Donation.');
    return $this->financialTypeId('Donation');
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
   * Sync a Square invoice.payment_failed webhook event.
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
      CRM_Core_Payment_SquareDebugLogger::log('Square: invoice.payment_failed missing invoice ID or subscription_id, skipping.');
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
            $this->updateContribution($contribution['id'], ['contribution_status_id' => $this->contributionStatusId('Failed')]);
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
   * Handle a subscription cancellation event coming from Square.
   *
   * Triggered by webhook event: subscription.canceled.
   *
   * @param array $payload
   *   Full decoded JSON body from Square webhook.
   */
  public function handleSubscriptionCancelled(array $payload) {
    if (empty($payload['data']['object']['subscription']['id'])) {
      CRM_Core_Payment_SquareDebugLogger::log('Square webhook: subscription.canceled missing subscription ID.');
      return;
    }

    $this->syncSubscriptionCancellationFromSquare($payload['data']['object']['subscription']['id']);
  }

  /**
   * Legacy entry point for on-site CC payments.
   *
   * CiviCRM still calls doDirectPayment for front-end payments.
   *
   * @param array $params
   *   Contribution / event params.
   *
   * @return array
   *
   * @throws \CRM_Core_Exception
   */
  public function doDirectPayment(&$params) {
    return $this->doPayment($params);
  }

  /**
   * Modern CiviCRM entry point for submitting payments (one-time or recurring).
   *
   * @param array $params
   *   Contribution or event payment parameters.
   * @param string $component
   *   Component name (e.g. 'contribute' or 'event'). Default 'contribute'.
   *
   * @return array
   *   Updated $params array.
   *
   * @throws \CRM_Core_Exception
   */
  public function doPayment(&$params, $component = 'contribute') {
    $this->_component = $component;
    // Determine if this is a recurring payment.
    if (!empty($params['is_recur']) || !empty($params['contributionRecurID'])) {
      return $this->doRecurPayment($params);
    }
    return $this->doOneTimePayment($params);
  }

  /**
   * Handle one-time Square payments.
   *
   * @param array $params
   *
   * @return array
   *
   * @throws \CRM_Core_Exception
   */
  protected function doOneTimePayment(&$params) {
    // 1. Determine amount and currency first — a zero-amount contribution
    // (e.g. a 100%-discount code) needs no card at all, so it must not be
    // rejected for lacking a Square token.
    $amount = $params['amount'] ?? $params['total_amount'] ?? NULL;
    if ($amount === NULL || $amount === '') {
      throw new \CRM_Core_Exception('Missing contribution amount.');
    }

    if ((float) $amount === 0.0) {
      // Nothing to charge: report it complete, as CRM_Core_Payment::doPayment()
      // does for a zero amount, so the checkout completes the contribution.
      $completedStatusId = $this->contributionStatusId('Completed');
      $params['payment_status_id'] = $completedStatusId;
      $params['payment_status'] = 'Completed';
      $params['contribution_status_id'] = $completedStatusId;
      return $params;
    }

    // 2. Extract Web Payments SDK token.
    //    Webform CiviCRM's confirm-form path does not always merge $_POST
    //    values into payment params, so we fall back to the request globals.
    $token = $params['square_payment_token']
      ?? $params['payment_token']
      ?? $params['token']
      ?? $_POST['square_payment_token']
      ?? $_REQUEST['square_payment_token']
      ?? NULL;

    if (!$token) {
      throw new \CRM_Core_Exception('Missing Square payment token.');
    }

    // Persist it back into $params so downstream code can see it.
    $params['square_payment_token'] = $token;

    $amountCents = (int) round(((float) $amount) * 100);
    $currency = $params['currency'] ?? $params['currencyID'] ?? 'USD';

    // 3. Idempotency key, derived from the checkout's own reference so that a
    // retried request for the same checkout can never charge twice. Every
    // CiviCRM checkout supplies one; without it a retry could not be told
    // apart from a new payment, so refuse rather than risk a second charge.
    $paymentReference = $params['invoiceID'] ?? $params['invoice_id'] ?? $params['contributionID'] ?? $params['contribution_id'] ?? NULL;
    if ($paymentReference === NULL || $paymentReference === '') {
      throw new \CRM_Core_Exception('Cannot take a Square payment without a CiviCRM invoice or contribution reference.');
    }
    $idempotencyKey = $this->idempotencyKey('payment', (string) $paymentReference);

    // 4. Build payload for CreatePayment.
    $requestValues = [
      'idempotencyKey' => $idempotencyKey,
      'sourceId' => $token,
      'amountMoney' => new Money(['amount' => $amountCents, 'currency' => $currency]),
      'locationId' => $this->getLocationId(),
    ];

    // Add customer ID if available (optional for one-off payments)
    $contactId = $params['contactID'] ?? $params['contact_id'] ?? NULL;
    if ($contactId) {
      $customerId = $this->getSquareCustomerId($contactId);
      if ($customerId) {
        $requestValues['customerId'] = $customerId;
      }
    }

    // Optional reference.
    if (!empty($params['invoiceID'])) {
      $requestValues['referenceId'] = (string) $params['invoiceID'];
    }

    // 5. Send request.
    $response = $this->callSquare(fn (SquareClient $client) => $client->payments->create(new CreatePaymentRequest($requestValues)));
    $payment = $response->getPayment();
    if (empty($payment) || empty($payment->getId())) {
      throw new \CRM_Core_Exception('Square payment failed: Missing payment ID.');
    }

    $trxnId = $payment->getId();

    // 6. Report the outcome; CiviCRM's checkout records the payment against
    // the returned trxn_id. A payment Square has authorized but not yet
    // settled is reported Pending — payment.updated completes it later.
    $isCompleted = $this->mapPaymentStatus($payment->getStatus() ?? 'UNKNOWN') === $this->contributionStatusId('Completed');
    $statusName = $isCompleted ? 'Completed' : 'Pending';
    $params['trxn_id'] = $trxnId;
    $params['payment_status_id'] = $this->contributionStatusId($statusName);
    $params['payment_status'] = $statusName;
    $params['contribution_status_id'] = $params['payment_status_id'];

    return $params;
  }

  /**
   * Set up a recurring payment as a Square subscription.
   *
   * - Expects a Web Payments SDK token in:
   *     - $params['square_payment_token'] or
   *     - $params['payment_token'] or
   *     - $params['token']
   * - Saves the card on file and creates a Square Subscription that starts
   *   immediately. Square itself charges the first installment, so no
   *   separate payment is made here, and the checkout's contribution is
   *   reported Pending: it is completed when Square confirms that charge
   *   (see recordSubscriptionInstallment()).
   *
   * @param array $params
   *   Contribution / participant params.
   *
   * @return array
   *   Updated params.
   *
   * @throws \CRM_Core_Exception
   */
  public function doRecurPayment(&$params) {
    // 1. Extract token from Web Payments SDK.
    //    Webform CiviCRM's confirm-form path does not always merge $_POST
    //    values into payment params, so we fall back to the request globals.
    $token = $params['square_payment_token']
      ?? $params['payment_token']
      ?? $params['token']
      ?? $_POST['square_payment_token']
      ?? $_REQUEST['square_payment_token']
      ?? NULL;

    if (!$token) {
      throw new CRM_Core_Exception('Missing Square card token for recurring payments.');
    }
    // Determine amount and currency.
    $amount = $params['amount'] ?? $params['total_amount'] ?? NULL;
    if ($amount === NULL || $amount === '') {
      throw new \CRM_Core_Exception('Missing contribution amount.');
    }
    $params['square_payment_token'] = $token;

    // 2. Ensure we have a valid Recurring Contribution ID from CiviCRM.
    $recurId = $params['contributionRecurID'] ?? NULL;
    if (!$recurId) {
      throw new CRM_Core_Exception('Missing contributionRecurID for Square recurring payments.');
    }

    // 3. Ensure customer exists / or create one
    $customerId = $this->ensureSquareCustomer($params);
    if ($this->findSquareCustomerById($customerId)) {
      CRM_Core_Payment_SquareDebugLogger::log("Square doRecurPayment: Found existing Square customer ID {$customerId} for CiviCRM recur ID {$recurId}");
      $this->updateSquareCustomerDetails($customerId, $params);
    }
    else {
      throw new CRM_Core_Exception("Failed to find or create Square customer for CiviCRM recur ID {$recurId}");
    }

    // 4. Fetch the Square card ID via the PaymentToken that
    // ensureSquareCustomer() -> createCardOnFile() just attached to this
    // recurring contribution. Card nonces are single-use, so we must not
    // redeem $token a second time here — ensureSquareCustomer() already
    // did that, and always redeems a fresh nonce into a brand new token,
    // so this is always the right one for this specific recur ID.
    $cardId = $this->getRecurCardId((int) $recurId);
    if (empty($cardId)) {
      throw new CRM_Core_Exception("Failed to attach a Square card on file for CiviCRM recur ID {$recurId}.");
    }
    CRM_Core_Payment_SquareDebugLogger::log("Square doRecurPayment: Using card ID {$cardId} for customer ID {$customerId} and CiviCRM recur ID {$recurId}");

    // 5. Determine plan ID
    $planVariationId = $this->getPlanVariationIdForParams($params);
    CRM_Core_Payment_SquareDebugLogger::log("Square doRecurPayment: Using plan variation ID {$planVariationId} for CiviCRM recur ID {$recurId}");

    // 6. Generate idempotency key tied to the recurring record so re-posts don't duplicate.
    $idempotencyKey = $this->idempotencyKey('subscription', (string) $recurId);
    $source = [
      'contact_id' => (string) ($params['contactID'] ?? $params['contact_id'] ?? ''),
      'recur_id' => (string) $recurId,
    ];
    // Implode key and value of source
    // convert array to string with maintain key value.
    $note = json_encode($source, JSON_UNESCAPED_SLASHES);

    // 7. Build subscription payload. startDate is deliberately omitted:
    // Square then starts the subscription today (in the location's time
    // zone) and immediately bills its first invoice to the card on file.
    // That charge is the first installment. Previously this code set
    // start_date to tomorrow AND made a separate immediate CreatePayment for
    // today, which charged the donor twice once Square billed its own first
    // invoice.
    $createSubscriptionRequest = new CreateSubscriptionRequest([
      'idempotencyKey' => $idempotencyKey,
      'locationId' => $this->getLocationId(),
      'planVariationId' => $planVariationId,
      'customerId' => $customerId,
      'cardId' => $cardId,
      'source' => new SubscriptionSource(['name' => $note]),
    ]);

    // 8. Send subscription create request
    $response = $this->callSquare(fn (SquareClient $client) => $client->subscriptions->create($createSubscriptionRequest));
    $subscription = $response->getSubscription();

    if (empty($subscription) || empty($subscription->getId())) {
      throw new CRM_Core_Exception('Failed to create Square subscription.');
    }
    $subscriptionId = $subscription->getId();
    CRM_Core_Payment_SquareDebugLogger::log('Square subscription created: ' . json_encode([
      'subscription_id' => $subscriptionId,
      'recur_id' => $recurId,
    ]));

    // 9. Link the subscription to the recurring contribution, which stays
    // Pending until its first payment is recorded.
    $this->saveRecurSubscription((int) $recurId, $subscriptionId);

    // 10. The checkout's contribution stays Pending until Square confirms
    // the first charge. No trxn_id is returned: the subscription ID
    // identifies the recurring agreement (saved above as the recur's
    // processor_id), not a payment, and webform_civicrm writes a returned
    // trxn_id straight onto the Pending contribution. Its trxn_id becomes
    // the Square payment ID when that payment is recorded.
    $params['trxn_id'] = NULL;
    $pendingStatusId = $this->contributionStatusId('Pending');
    $params['payment_status_id'] = $pendingStatusId;
    $params['payment_status'] = 'Pending';
    $params['contribution_status_id'] = $pendingStatusId;
    $params['subscription_id'] = $subscriptionId;

    return $params;
  }

  /**
   * Square card ID saved as the recurring contribution's payment token.
   *
   * @param int $recurId
   *
   * @return string|null
   */
  protected function getRecurCardId(int $recurId): ?string {
    $recurForToken = ContributionRecur::get(FALSE)
      ->addWhere('id', '=', $recurId)
      ->addSelect('payment_token_id')
      ->execute()
      ->first();
    $paymentToken = empty($recurForToken['payment_token_id']) ? NULL : PaymentToken::get(FALSE)
      ->addWhere('id', '=', $recurForToken['payment_token_id'])
      ->addSelect('token')
      ->execute()
      ->first();
    return $paymentToken['token'] ?? NULL;
  }

  /**
   * Link a Square subscription to its recurring contribution.
   *
   * @param int $recurId
   * @param string $subscriptionId
   *
   * @throws \CRM_Core_Exception
   */
  protected function saveRecurSubscription(int $recurId, string $subscriptionId): void {
    ContributionRecur::update(FALSE)
      ->addWhere('id', '=', $recurId)
      ->addValue('processor_id', $subscriptionId)
      ->addValue('trxn_id', $subscriptionId)
      ->addValue('contribution_status_id', $this->contributionStatusId('Pending'))
      ->execute();
  }

  /**
   * Whether this processor supports recurring payments.
   *
   * @return bool
   */
  public function supportsRecurring() {
    return TRUE;
  }

  /**
   * Whether this processor supports refunds.
   *
   * @return bool
   */
  public function supportsRefund() {
    return TRUE;
  }

  /**
   * Perform a refund via Square Refunds API.
   *
   * @param array $params
   *
   * @return array
   *
   * @throws \CRM_Core_Exception
   */
  public function doRefund(&$params) {
    $trxnId = $params['trxn_id'] ?? $params['transaction_id'] ?? NULL;
    if (empty($trxnId)) {
      throw new CRM_Core_Exception('Missing transaction ID for refund.');
    }

    if (empty($params['amount'])) {
      throw new CRM_Core_Exception('Missing refund amount.');
    }

    $rawAmount = (float) $params['amount'];
    $amountInCents = (int) round($rawAmount * 100);
    if ($amountInCents <= 0) {
      throw new CRM_Core_Exception('Refund amount must be greater than zero.');
    }

    $currency = $params['currencyID'] ?? $params['currency'] ?? 'USD';

    $refundRequest = new RefundPaymentRequest([
      'idempotencyKey' => $this->idempotencyKey('refund', (string) $trxnId . ':' . $amountInCents),
      'paymentId' => $trxnId,
      'amountMoney' => new Money(['amount' => $amountInCents, 'currency' => $currency]),
    ]);

    $response = $this->createRefund($refundRequest);
    $refund = $response->getRefund();

    if (empty($refund) || empty($refund->getId())) {
      $msg = 'Square refund failed: unexpected response.';
      throw new CRM_Core_Exception($msg);
    }

    $status = $refund->getStatus() ?? 'UNKNOWN';

    if (!in_array($status, ['PENDING', 'COMPLETED', 'APPROVED'], TRUE)) {
      $msg = "Square refund not completed. Status: {$status}";
      throw new CRM_Core_Exception($msg);
    }

    return [
      'refund_status' => $status,
      'refund_trxn_id' => $refund->getId(),
    ];
  }

  /**
   * Call the Square Refunds API through a testable seam.
   *
   * @param \Square\Refunds\Requests\RefundPaymentRequest $request
   *
   * @return \Square\Types\RefundPaymentResponse
   *
   * @throws \CRM_Core_Exception
   */
  protected function createRefund(RefundPaymentRequest $request) {
    return $this->callSquare(fn (SquareClient $client) => $client->refunds->refundPayment($request));
  }

  /**
   * Invoke the Square SDK client and translate its exceptions to CRM_Core_Exception.
   *
   * @param callable(\Square\SquareClient): mixed $call
   *
   * @return mixed
   *   Whatever $call returns (typically a Square SDK response object).
   *
   * @throws \CRM_Core_Exception
   */
  protected function callSquare(callable $call) {
    try {
      return $call($this->buildSquareClient());
    }
    catch (SquareApiException $e) {
      throw $this->squareApiError($e);
    }
    catch (SquareException $e) {
      // Transport-level failure (timeout, DNS, connection reset, etc.) —
      // there's no reason to believe retrying would fail again the same
      // way, so callers processing a queued webhook should retry rather
      // than give up permanently.
      throw new CRM_Core_Payment_SquareRetryableException('Square API request failed: ' . $e->getMessage());
    }
  }

  /**
   * Convert a Square SDK API exception to the message shape callers expect.
   *
   * HTTP 5xx and 429 are treated as transient (Square-side outage or rate
   * limiting) so webhook processing retries them; 4xx (other than 429)
   * indicates a malformed/rejected request that will fail identically on
   * retry, so it is treated as permanent.
   *
   * @param \Square\Exceptions\SquareApiException $e
   *
   * @return \CRM_Core_Exception
   */
  protected function squareApiError(SquareApiException $e): CRM_Core_Exception {
    $errorDetails = [];
    foreach ($e->getErrors() as $err) {
      $code = $err->getCode() ?: 'UNKNOWN';
      $detail = $err->getDetail() ?? '';
      $errorDetails[] = "{$code}: {$detail}";
    }
    $statusCode = $e->getStatusCode();
    $msg = "Square API returned HTTP {$statusCode}.";
    if ($errorDetails) {
      $msg .= ' ' . implode(' | ', $errorDetails);
    }
    $exceptionClass = ($statusCode >= 500 || $statusCode === 429)
      ? CRM_Core_Payment_SquareRetryableException::class
      : CRM_Core_Exception::class;
    return new $exceptionClass($msg);
  }

  /**
   * Look up an existing Square customer by email.
   *
   * @param string $email
   *   Customer email address.
   *
   * @return string|null
   */
  protected function findSquareCustomerByEmail($email) {
    if (empty($email)) {
      return NULL;
    }

    $response = $this->callSquare(fn (SquareClient $client) => $client->customers->search(new SearchCustomersRequest([
      'query' => new CustomerQuery([
        'filter' => new CustomerFilter([
          'emailAddress' => new CustomerTextFilter(['exact' => $email]),
        ]),
      ]),
    ])));

    $customers = $response->getCustomers();
    if (!empty($customers[0])) {
      return $customers[0]->getId();
    }

    return NULL;
  }

  /**
   * Look up an existing Square customer by ID.
   *
   * @param string $customerID
   *   Square customer ID.
   *
   * @return string|null
   */
  protected function findSquareCustomerById($customerID) {
    if (empty($customerID)) {
      return NULL;
    }

    $response = $this->callSquare(fn (SquareClient $client) => $client->customers->get(
      new GetCustomersRequest(['customerId' => $customerID])
    ));
    $customer = $response->getCustomer();
    if (!empty($customer) && !empty($customer->getId())) {
      return $customer->getId();
    }

    return NULL;
  }

  /**
   * Update a Square customer's details from CiviCRM payment parameters.
   *
   * @param string $customerID
   *   Square customer ID.
   * @param array $params
   *   CiviCRM payment parameters.
   *
   * @return void
   *
   * @throws CRM_Core_Exception
   */
  protected function updateSquareCustomerDetails($customerID, $params) {
    $firstName = $params['first_name'] ?? NULL;
    $lastName = $params['last_name'] ?? NULL;
    $email = $params['email'] ?? $params['email-5'] ?? NULL;
    $contactID = $params['contactID'] ?? $params['contact_id'] ?? NULL;

    $requestValues = ['customerId' => $customerID];
    if (!empty($firstName)) {
      $requestValues['givenName'] = $firstName;
    }
    if (!empty($lastName)) {
      $requestValues['familyName'] = $lastName;
    }
    if (!empty($email)) {
      $requestValues['emailAddress'] = $email;
    }
    if (!empty($contactID)) {
      $requestValues['referenceId'] = (string) $contactID;
    }

    $this->callSquare(fn (SquareClient $client) => $client->customers->update(new UpdateCustomerRequest($requestValues)));
  }

  /**
   * Ensure a Square customer exists for this contact. Also handles storing card_id if available.
   *
   * 1. Check for a stored Square Customer ID in a custom field.
   * 2. If none exists, check for existing Square customer by email.
   * 3. If still none, create a new customer in Square.
   * 4. Persist the new customer ID back to the contact.
   * 5. If card token/nonce is present in $params, create and store the card_id as well.
   *
   * @param array $params
   *   Contribution params (includes contactID/contact_id).
   *
   * @return string
   *   Square customer ID.
   *
   * @throws CRM_Core_Exception
   */
  public function ensureSquareCustomer(array $params) {
    $contactID = $params['contactID'] ?? $params['contact_id'] ?? NULL;
    if (!$contactID) {
      throw new CRM_Core_Exception('Missing contactID in params for Square recurring payment.');
    }

    $contactID = (int) $contactID;

    // 1. Check if we already have a stored Square Customer ID.
    if ($customerId = $this->getSquareCustomerId($contactID)) {
      CRM_Core_Payment_SquareDebugLogger::log('Square customer already exists for contact ' . $contactID . ': ' . $customerId);
      // If a fresh card token/nonce was submitted (e.g. a returning donor
      // entering a new card), attach and store it. Card nonces are
      // single-use, so this must be the only place that redeems it.
      $cardNonce = $params['square_payment_token']
        ?? $params['payment_token']
        ?? $params['token']
        ?? NULL;
      if (!empty($cardNonce)) {
        $this->createCardOnFile($customerId, $cardNonce, $params, $contactID);
      }
      return $customerId;
    }
    // Migration logic: check whether this contact already exists in Square based on reference_id.
    // If Square already has a customer with reference_id == Civi contact ID, we adopt that one.
    try {
      $lookupResponse = $this->callSquare(fn (SquareClient $client) => $client->customers->search(new SearchCustomersRequest([
        'query' => new CustomerQuery([
          'filter' => new CustomerFilter([
            'referenceId' => new CustomerTextFilter(['exact' => (string) $contactID]),
          ]),
        ]),
      ])));
      $migratedCustomers = $lookupResponse->getCustomers();
      if (!empty($migratedCustomers[0])) {
        $migratedCustomerId = $migratedCustomers[0]->getId();
        // Check if another Civi contact already mapped to this customerId
        // (for this payment processor).
        $existingContactId = CRM_Core_DAO::singleValueQuery(
          'SELECT contact_id FROM square_customer_map WHERE square_customer_id = %1 AND payment_processor_id = %2',
          [1 => [$migratedCustomerId, 'String'], 2 => [(int) ($this->_paymentProcessor['id'] ?? 0), 'Integer']]
        );

        if (!empty($existingContactId) && (int) $existingContactId !== $contactID) {
          throw new CRM_Core_Exception(
            "Square customer {$migratedCustomerId} already mapped to a different CiviCRM contact ({$existingContactId})."
          );
        }

        // Store mapping if safe.
        $this->saveSquareCustomerId($contactID, $migratedCustomerId);

        // If a card token is present, attach card to this existing Square customer.
        $cardNonce = $params['square_payment_token']
          ?? $params['payment_token']
          ?? $params['token']
          ?? NULL;

        if (!empty($cardNonce)) {
          $this->createCardOnFile($migratedCustomerId, $cardNonce, $params, $contactID);
        }

        return $migratedCustomerId;
      }
    }
    catch (\Throwable $e) {
      CRM_Core_Payment_SquareDebugLogger::log('Square migration lookup error: ' . $e->getMessage());
    }
    $existingCustomerId = $this->getSquareCustomerId($contactID);
    if (!empty($existingCustomerId)) {
      // If card token/nonce is present, create and store card_id.
      $cardNonce = $params['square_payment_token']
        ?? $params['payment_token']
        ?? $params['token']
        ?? NULL;
      if (!empty($cardNonce)) {
        $this->createCardOnFile($existingCustomerId, $cardNonce, $params, $contactID);
      }
      return $existingCustomerId;
    }

    // Load contact email.
    $contact = Contact::get(FALSE)
      ->addWhere('id', '=', $contactID)
      ->addSelect('email')
      ->execute()
      ->first();

    $email = $contact['email'] ?? NULL;

    // Check for existing Square customer by email.
    $squareCustomerByEmail = $this->findSquareCustomerByEmail($email);

    if (!empty($squareCustomerByEmail)) {
      // Check if mapped to another contact (for this payment processor).
      $existingContactId = CRM_Core_DAO::singleValueQuery(
        'SELECT contact_id FROM square_customer_map WHERE square_customer_id = %1 AND payment_processor_id = %2',
        [1 => [$squareCustomerByEmail, 'String'], 2 => [(int) ($this->_paymentProcessor['id'] ?? 0), 'Integer']]
      );

      if (!empty($existingContactId) && (int) $existingContactId !== $contactID) {
        throw new CRM_Core_Exception('This email address is already associated with a different Square customer in our system.');
      }

      // Save mapping if none existed previously.
      $this->saveSquareCustomerId($contactID, $squareCustomerByEmail);
      // If card token/nonce is present, create and store card_id.
      $cardNonce = $params['square_payment_token']
        ?? $params['payment_token']
        ?? $params['token']
        ?? NULL;
      if (!empty($cardNonce)) {
        $this->createCardOnFile($squareCustomerByEmail, $cardNonce, $params, $contactID);
      }
      return $squareCustomerByEmail;
    }

    // 2. Load contact info from CiviCRM using API4 for customer creation.
    $contactInfo = Contact::get(FALSE)
      ->addWhere('id', '=', $contactID)
      ->addSelect('first_name', 'last_name', 'email')
      ->execute()
      ->first();

    if (empty($contactInfo)) {
      throw new CRM_Core_Exception("Unable to load contact {$contactID} for Square customer creation.");
    }

    $firstName = $contactInfo['first_name'] ?? NULL;
    $lastName = $contactInfo['last_name'] ?? NULL;
    $email = $contactInfo['email'] ?? NULL;

    $createResponse = $this->callSquare(fn (SquareClient $client) => $client->customers->create(new CreateCustomerRequest([
      'givenName' => $firstName,
      'familyName' => $lastName,
      'emailAddress' => $email,
      'referenceId' => (string) $contactID,
    ])));

    $newCustomer = $createResponse->getCustomer();
    if (empty($newCustomer) || empty($newCustomer->getId())) {
      throw new CRM_Core_Exception('Failed to create Square customer.');
    }

    $customerId = $newCustomer->getId();

    // 3. Persist the customer ID mapping for this contact + processor.
    $this->saveSquareCustomerId($contactID, $customerId);

    // If card token/nonce is present, create and store card_id.
    $cardNonce = $params['square_payment_token']
      ?? $params['payment_token']
      ?? $params['token']
      ?? NULL;
    if (!empty($cardNonce)) {
      $this->createCardOnFile($customerId, $cardNonce, $params, $contactID);
    }

    return $customerId;
  }

  /**
   * Attach a card to the Square customer using the tokenized card nonce.
   *
   * @param string $customerId
   *   Square customer ID.
   * @param string $cardNonce
   *   Token from Web Payments SDK.
   * @param array $params
   *   Additional parameters, possibly including verification_token.
   * @param int|null $contactId
   *   CiviCRM contact ID (optional, but required to record a PaymentToken).
   *
   * @return string
   *   Square card ID.
   *
   * @throws \CRM_Core_Exception
   */
  public function createCardOnFile($customerId, $cardNonce, array $params = [], $contactId = NULL) {
    if (empty($cardNonce)) {
      throw new CRM_Core_Exception('Missing Square card token for recurring payment.');
    }

    $cardValues = ['customerId' => $customerId];

    // Add billing address if available.
    if (!empty($params['billing_address'])) {
      $cardValues['billingAddress'] = new Address([
        'addressLine1' => $params['billing_address']['street_address'] ?? NULL,
        'addressLine2' => $params['billing_address']['street_address_2'] ?? NULL,
        'locality' => $params['billing_address']['city'] ?? NULL,
        'administrativeDistrictLevel1' => $params['billing_address']['state'] ?? NULL,
        'postalCode' => $params['billing_address']['postal_code'] ?? NULL,
        'country' => $this->mapCountryCode($params['billing_address']['country'] ?? 'US'),
      ]);
    }

    $requestValues = [
      // Deterministic on the (single-use) nonce, rather than uniqid() —
      // uniqid() is never the same twice, which defeats the point of an
      // idempotency key: an accidental double-submit with the same nonce
      // would otherwise create two cards.
      'idempotencyKey' => $this->idempotencyKey('card', $cardNonce),
      'sourceId' => $cardNonce,
      'card' => new Card($cardValues),
    ];
    // Square requires verification_token for AVS/SCA under certain conditions.
    if (!empty($params['verification_token'])) {
      $requestValues['verificationToken'] = $params['verification_token'];
    }

    try {
      $response = $this->buildSquareClient()->cards->create(new CreateCardRequest($requestValues));
    }
    catch (SquareApiException $e) {
      // Translate structured Square card errors into a human-friendly message.
      throw new CRM_Core_Exception($this->translateSquareCardError($e->getErrors()));
    }
    catch (SquareException $e) {
      throw new CRM_Core_Exception('Square API request failed: ' . $e->getMessage());
    }

    $card = $response->getCard();
    if (empty($card) || empty($card->getId())) {
      throw new CRM_Core_Exception('Failed to create card on file with Square.');
    }

    $cardId = $card->getId();

    // Record this card as a CiviCRM PaymentToken (instead of a custom
    // field) so multiple cards per contact are properly distinguished, and
    // link it to the recurring contribution it was created for, if any.
    if (!empty($contactId)) {
      $expiryDate = NULL;
      if ($card->getExpYear() && $card->getExpMonth()) {
        $expiryDate = date('Y-m-t', mktime(0, 0, 0, (int) $card->getExpMonth(), 1, (int) $card->getExpYear()));
      }

      $tokenCreate = PaymentToken::create(FALSE)
        ->addValue('contact_id', (int) $contactId)
        ->addValue('payment_processor_id', (int) ($this->_paymentProcessor['id'] ?? 0))
        ->addValue('token', $cardId)
        ->addValue('masked_account_number', $card->getLast4());
      if ($expiryDate) {
        $tokenCreate->addValue('expiry_date', $expiryDate);
      }
      if (!empty($params['first_name'])) {
        $tokenCreate->addValue('billing_first_name', $params['first_name']);
      }
      if (!empty($params['last_name'])) {
        $tokenCreate->addValue('billing_last_name', $params['last_name']);
      }
      $email = $params['email'] ?? $params['email-5'] ?? NULL;
      if (!empty($email)) {
        $tokenCreate->addValue('email', $email);
      }
      $paymentToken = $tokenCreate->execute()->first();

      if (!empty($paymentToken['id']) && !empty($params['contributionRecurID'])) {
        ContributionRecur::update(FALSE)
          ->addWhere('id', '=', (int) $params['contributionRecurID'])
          ->addValue('payment_token_id', $paymentToken['id'])
          ->execute();
      }
    }

    return $cardId;
  }

  /**
   * Map country to standardized 2-letter country code.
   */
  protected function mapCountryCode($country) {
    // Standardize country codes/names.
    $countryMap = [
      'US' => 'US',
      'USA' => 'US',
      'UNITED STATES' => 'US',
      'UNITED STATES OF AMERICA' => 'US',
      'CA' => 'CA',
      'CANADA' => 'CA',
      'GB' => 'GB',
      'UK' => 'GB',
      'UNITED KINGDOM' => 'GB',
    ];

    // Normalize input.
    $normalizedCountry = strtoupper(trim($country));

    // Return mapped country or default to US.
    return $countryMap[$normalizedCountry] ?? 'US';
  }

  /**
   * Translate Square card errors to human-friendly messages.
   *
   * @param \Square\Types\Error[] $errors
   *   Square card errors.
   *
   * @return string
   */
  protected function translateSquareCardError(array $errors) {
    $messages = [];

    foreach ($errors as $err) {
      $code = $err->getCode() ?? '';
      switch ($code) {
        case 'CARD_DECLINED':
          $messages[] = 'Your card was declined. Please use a different card.';
          break;

        case 'GENERIC_DECLINE':
          $messages[] = 'The card was declined by the bank.';
          break;

        case 'INVALID_EXPIRATION':
          $messages[] = 'The card expiration date is invalid.';
          break;

        case 'CVV_FAILURE':
          $messages[] = 'The CVV security code is incorrect.';
          break;

        case 'ADDRESS_VERIFICATION_FAILURE':
          $messages[] = 'The billing ZIP/postal code did not match the card.';
          break;

        case 'INSUFFICIENT_FUNDS':
          $messages[] = 'The card has insufficient funds.';
          break;

        default:
          if (!empty($err->getDetail())) {
            $messages[] = $err->getDetail();
          }
          else {
            $messages[] = 'The card could not be processed.';
          }
          break;
      }
    }

    return implode(' ', $messages);
  }

  /**
   * Get the plan variation ID for recurring-payment parameters.
   */
  protected function getPlanVariationIdForParams(array $params): string {
    $entity = $params['component'] ?? 'contribute';
    $amount = (float) ($params['amount'] ?? 0);
    $currency = $params['currency'] ?? 'USD';
    $intervalUnit = $params['frequency_unit'] ?? '';
    $intervalStep = (int) ($params['frequency_interval'] ?? 1);
    $installments = (int) ($params['installments'] ?? 0);
    if (!$amount || !$intervalUnit) {
      throw new CRM_Core_Exception('Amount and cadence are required for Square subscription.');
    }

    // A plan identifies a subscription purpose; a variation identifies its amount and cadence.
    $planName = sprintf(
      'CiviCRM %s',
      ucfirst($entity)
    );

    $planId = $this->getOrCreateSubscriptionPlan($planName);
    CRM_Core_Payment_SquareDebugLogger::log("Square plan ID for {$entity}: {$planId}");
    return $this->getOrCreatePlanVariation(
      $planId, $amount,
      $currency, $intervalUnit,
      $intervalStep, $installments
    );
  }

  /**
   * Cancel a recurring contribution at Square.
   *
   * Overrides CRM_Core_Payment::doCancelRecurring() so CiviCRM core calls this
   * directly and never falls back to the legacy cancelSubscription() path (which
   * used an incompatible two-argument signature causing a TypeError).
   *
   * @param \Civi\Payment\PropertyBag $propertyBag
   *
   * @return array
   *
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   */
  public function doCancelRecurring(PropertyBag $propertyBag): array {
    if (!$propertyBag->has('isNotifyProcessorOnCancelRecur')) {
      $propertyBag->setIsNotifyProcessorOnCancelRecur(TRUE);
    }

    if (!$propertyBag->getIsNotifyProcessorOnCancelRecur()) {
      return ['message' => E::ts('Successfully cancelled the subscription in CiviCRM ONLY.')];
    }

    if (!$propertyBag->has('recurProcessorID')) {
      $errorMessage = E::ts('The recurring contribution cannot be cancelled (no Square subscription ID found).');
      \Civi::log('square')->error($errorMessage);
      throw new PaymentProcessorException($errorMessage);
    }

    try {
      $this->cancelSquareSubscription($propertyBag->getRecurProcessorID());
    }
    catch (PaymentProcessorException $e) {
      throw $e;
    }
    catch (\Throwable $e) {
      $errorMessage = E::ts('Failed to cancel the Square subscription: ') . $e->getMessage();
      \Civi::log('square')->error($errorMessage);
      // $previous is CRM_Core_Exception's 4th constructor argument
      // ($message, $error_code, $errorData, $previous) — passing $e as the
      // 3rd argument silently drops it into the $errorData slot instead.
      throw new PaymentProcessorException($errorMessage, 0, [], $e);
    }

    return ['message' => E::ts('Successfully cancelled the Square subscription.')];
  }

  /**
   * Cancel a Square subscription by its processor ID.
   *
   * Low-level helper used by doCancelRecurring() and by the civicrm_post hook.
   * Makes the Square API call directly without PropertyBag logic.
   *
   * @param string $subscriptionId
   *   Square subscription ID (e.g. "SUB_xxx").
   *
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   */
  public function cancelSquareSubscription(string $subscriptionId): void {
    if (empty($subscriptionId)) {
      throw new PaymentProcessorException(E::ts('Cannot cancel Square subscription: empty subscription ID.'));
    }

    $this->callSquare(fn (SquareClient $client) => $client->subscriptions->cancel(
      new CancelSubscriptionsRequest(['subscriptionId' => $subscriptionId])
    ));
  }

  /**
   * Get the Square customer ID stored for a contact and processor.
   *
   * Live and sandbox accounts use distinct customer records.
   *
   * @param int $contactId
   *
   * @return string|null
   */
  protected function getSquareCustomerId($contactId) {
    $contactId = (int) $contactId;
    $processorId = (int) ($this->_paymentProcessor['id'] ?? 0);
    if ($contactId <= 0 || $processorId <= 0) {
      return NULL;
    }

    $customerId = CRM_Core_DAO::singleValueQuery(
      'SELECT square_customer_id FROM square_customer_map WHERE contact_id = %1 AND payment_processor_id = %2',
      [1 => [$contactId, 'Integer'], 2 => [$processorId, 'Integer']]
    );

    return $customerId !== NULL ? (string) $customerId : NULL;
  }

  /**
   * Save the Square customer ID for a contact and processor.
   *
   * See getSquareCustomerId().
   *
   * @param int $contactId
   * @param string $customerId
   */
  protected function saveSquareCustomerId($contactId, $customerId) {
    $contactId = (int) $contactId;
    $processorId = (int) ($this->_paymentProcessor['id'] ?? 0);
    if ($contactId <= 0 || $processorId <= 0 || empty($customerId)) {
      return;
    }

    // An existing mapping is never re-pointed at a different customer
    // (e.g. by two concurrent checkouts for the same contact): the insert is
    // a no-op if the contact already has one, and a conflict is logged.
    CRM_Core_DAO::executeQuery(
      'INSERT INTO square_customer_map (contact_id, payment_processor_id, square_customer_id)
       VALUES (%1, %2, %3)
       ON DUPLICATE KEY UPDATE square_customer_id = square_customer_id',
      [1 => [$contactId, 'Integer'], 2 => [$processorId, 'Integer'], 3 => [$customerId, 'String']]
    );
    $mappedCustomerId = $this->getSquareCustomerId($contactId);
    if ($mappedCustomerId !== NULL && $mappedCustomerId !== (string) $customerId) {
      Civi::log()->warning("Square: contact {$contactId} is already mapped to Square customer {$mappedCustomerId} on payment processor {$processorId}; not re-mapping it to {$customerId}.");
    }
  }

  /**
   * Whether processor supports back-office (admin) payments.
   *
   * For now, we only support front-end Web Payments SDK tokenization.
   *
   * @return bool
   */
  public function supportsBackOffice() {
    // You can change this to TRUE if you later support card entry in admin UI.
    return FALSE;
  }

  /**
   * Does this processor support cancelling recurring contributions through code.
   *
   * If the processor returns true it must be possible to take action from within CiviCRM
   * that will result in no further payments being processed.
   *
   * @return bool
   */
  protected function supportsCancelRecurring() {
    return TRUE;
  }

  /**
   * Does the processor support the user having a choice as to whether to cancel the recurring with the processor?
   *
   * If this returns TRUE then there will be an option to send a cancellation request in the cancellation form.
   *
   * This would normally be false for processors where CiviCRM maintains the schedule.
   *
   * @return bool
   */
  protected function supportsCancelRecurringNotifyOptional() {
    return TRUE;
  }

  /**
   * Get or create a subscription plan variation with cadence.
   *
   * @param string $planId
   * @param float $amount
   * @param string $currency
   * @param string $intervalUnit
   *   Day, week, month, or year.
   * @param int $intervalStep
   * @param int|null $installments
   *
   * @return string Plan variation ID
   *
   * @throws CRM_Core_Exception
   */
  protected function getOrCreatePlanVariation(
    string $planId,
    float $amount,
    string $currency,
    string $intervalUnit,
    int $intervalStep = 1,
    ?int $installments = NULL,
  ): string {
    CRM_Core_Payment_SquareDebugLogger::log("Looking up Square plan variation: {$planId}, {$amount} {$currency}, every {$intervalStep} {$intervalUnit}");
    $cadence = $this->resolveCadence($intervalUnit, $intervalStep);
    CRM_Core_Payment_SquareDebugLogger::log("Resolved Square cadence: {$cadence}");
    // Scope the cache by everything that changes what gets created in
    // Square, not just plan/cadence/amount — two processor records (or a
    // staging clone) pointing at the same Square account/location must
    // never reuse each other's cached variation IDs, and a finite plan must
    // never reuse an open-ended one's.
    $cacheKey = implode('_', [
      $this->processorId(),
      $this->isTestMode() ? 'test' : 'live',
      $this->getLocationId(),
      $planId,
      $cadence,
      $amount,
      $currency,
      $installments ?: 'indefinite',
    ]);
    $cache = Civi::settings()->get('org_square_plan_variation_cache') ?? [];

    if (!empty($cache[$cacheKey])) {
      return $cache[$cacheKey];
    }

    $label = sprintf(
      '%s %0.2f %s',
      $cadence,
      $amount,
      $currency
    );
    CRM_Core_Payment_SquareDebugLogger::log("Creating label Square plan variation: {$label}");
    $amountCents = (int) round($amount * 100);

    $phaseValues = [
      'ordinal' => 0,
    // MONTHLY, ANNUAL.
      'cadence' => strtoupper($cadence),
      'pricing' => new SubscriptionPricing([
        'type' => 'STATIC',
        'priceMoney' => new Money(['amount' => $amountCents, 'currency' => $currency]),
      ]),
    ];
    // Only finite subscriptions get a periods count — sending periods: 0
    // for an open-ended subscription is wrong (0 periods, not indefinite);
    // omitting the field entirely is what tells Square the phase never
    // ends. Square bills the first period itself (see doRecurPayment()), so
    // periods equals CiviCRM's installments.
    if (!empty($installments)) {
      $phaseValues['periods'] = $installments;
    }
    $phase = new SubscriptionPhase($phaseValues);

    $catalogObject = CatalogObject::subscriptionPlanVariation(new CatalogObjectSubscriptionPlanVariation([
      'id' => '#var_' . md5($cacheKey),
      'subscriptionPlanVariationData' => new CatalogSubscriptionPlanVariation([
        'name' => $label,
        'subscriptionPlanId' => $planId,
        'phases' => [$phase],
      ]),
    ]));

    $response = $this->callSquare(fn (SquareClient $client) => $client->catalog->batchUpsert(new BatchUpsertCatalogObjectsRequest([
      'idempotencyKey' => $this->idempotencyKey('plan-variation', $cacheKey),
      'batches' => [new CatalogObjectBatch(['objects' => [$catalogObject]])],
    ])));

    $objects = $response->getObjects();
    $variationId = !empty($objects[0]) ? $objects[0]->getValue()->getId() : NULL;
    CRM_Core_Payment_SquareDebugLogger::log("Created Square plan variation ID: {$variationId}");
    if (!$variationId) {
      throw new CRM_Core_Exception('Failed to create Square plan variation.');
    }

    $cache[$cacheKey] = $variationId;
    Civi::settings()->set('org_square_plan_variation_cache', $cache);

    return $variationId;
  }

  /**
   * Resolve Square cadence from interval unit + frequency.
   *
   * @param string $unit
   *   Day, week, month, or year.
   * @param int $step
   *
   * @return string
   *
   * @throws CRM_Core_Exception
   */
  protected function resolveCadence(string $unit, int $step): string {

    foreach (self::SQUARE_CADENCES as $cadence => $def) {
      if ($def['unit'] === $unit && $def['step'] === $step) {
        return $cadence;
      }
    }

    throw new CRM_Core_Exception(
      "Unsupported Square cadence: every {$step} {$unit}(s)"
    );
  }

  /**
   * Get or create a Square Subscription Plan.
   *
   * @param string $name
   *
   * @return string Plan ID
   *
   * @throws CRM_Core_Exception
   */
  protected function getOrCreateSubscriptionPlan(string $name): string {
    // Scope the cache by processor + environment + location, same reasoning
    // as getOrCreatePlanVariation().
    $cacheKey = implode('_', [
      $this->processorId(),
      $this->isTestMode() ? 'test' : 'live',
      $this->getLocationId(),
      $name,
    ]);
    $cache = Civi::settings()->get('org_square_plan_cache') ?? [];

    if (!empty($cache[$cacheKey])) {
      return $cache[$cacheKey];
    }

    $catalogObject = CatalogObject::subscriptionPlan(new CatalogObjectSubscriptionPlan([
      'id' => '#plan_' . md5($cacheKey),
      'subscriptionPlanData' => new CatalogSubscriptionPlan([
        'name' => $name,
      ]),
    ]));

    $response = $this->callSquare(fn (SquareClient $client) => $client->catalog->batchUpsert(new BatchUpsertCatalogObjectsRequest([
      'idempotencyKey' => $this->idempotencyKey('plan', $cacheKey),
      'batches' => [new CatalogObjectBatch(['objects' => [$catalogObject]])],
    ])));

    $objects = $response->getObjects();
    $planId = !empty($objects[0]) ? $objects[0]->getValue()->getId() : NULL;
    if (!$planId) {
      throw new CRM_Core_Exception('Failed to create Square subscription plan.');
    }

    $cache[$cacheKey] = $planId;
    Civi::settings()->set('org_square_plan_cache', $cache);

    return $planId;
  }

  /**
   * Map Square payment statuses to CiviCRM contribution status IDs.
   *
   * @param string $squareStatus
   *   Status from Square API (e.g., 'COMPLETED', 'PENDING', 'FAILED', 'CANCELED').
   *
   * @return int|null
   *   CiviCRM contribution_status_id or NULL if unmapped.
   */
  protected function mapPaymentStatus($squareStatus) {
    $squareStatus = strtoupper(trim($squareStatus));

    switch ($squareStatus) {
      case 'COMPLETED':
        return $this->contributionStatusId('Completed');

      // APPROVED: authorized, but the funds are not captured yet.
      case 'APPROVED':
      case 'PENDING':
      case 'PROCESSING':
        return $this->contributionStatusId('Pending');

      case 'FAILED':
      case 'DECLINED':
      case 'CANCELED':
        return $this->contributionStatusId('Failed');

      case 'REFUNDED':
        return $this->contributionStatusId('Refunded');

      default:
        return NULL;
    }
  }

  /**
   * Map a Square source_type to a CiviCRM payment_instrument_id.
   *
   * Square source_type values: CARD, BANK_ACCOUNT, WALLET, CASH, EXTERNAL,
   * BUY_NOW_PAY_LATER, SQUARE_ACCOUNT.
   *
   * Returns CiviCRM's Credit Card, EFT or Cash payment instrument, resolved
   * by name.
   */
  protected function mapPaymentInstrument(?string $sourceType): int {
    switch (strtoupper((string) $sourceType)) {
      case 'CARD':
        // Apple Pay / Google Pay / Cash App Pay tokenize as cards.
      case 'WALLET':
      case 'BUY_NOW_PAY_LATER':
      case 'SQUARE_ACCOUNT':
        return $this->paymentInstrumentId('Credit Card');

      case 'BANK_ACCOUNT':
        return $this->paymentInstrumentId('EFT');

      case 'CASH':
        return $this->paymentInstrumentId('Cash');

      default:
        // Credit Card (Square's most common instrument)
        return $this->paymentInstrumentId('Credit Card');
    }
  }

  /**
   * Find the contact ID associated with a Square payment.
   *
   * Attempts to resolve contact by:
   * 1. Customer reference_id (if set in Square)
   * 2. Email address from payment receipt
   * 3. Contribution reference_id if payment is linked to existing contribution.
   *
   * @param array $payment
   *   Payment object from Square API.
   *
   * @return int|null
   *   CiviCRM contact ID or NULL if not found.
   */
  protected function findContactIdForPayment(array $payment) {
    // 1. Try to find via customer reference_id
    $customerId = $payment['customer_id'] ?? NULL;
    if ($customerId) {
      try {
        $customerResponse = $this->buildSquareClient()->customers->get(new GetCustomersRequest(['customerId' => $customerId]));
        $customer = $customerResponse->getCustomer();
        if (!empty($customer) && !empty($customer->getReferenceId())) {
          $refId = $customer->getReferenceId();
          if (ctype_digit((string) $refId)) {
            $contact = Contact::get(FALSE)
              ->addWhere('id', '=', (int) $refId)
              ->addSelect('id')
              ->execute()
              ->first();
            if (!empty($contact)) {
              return (int) $contact['id'];
            }
          }
        }
      }
      catch (\Throwable $e) {
        CRM_Core_Payment_SquareDebugLogger::log('Square findContactIdForPayment(): customer lookup error: ' . $e->getMessage());
      }
    }

    // 2. Try via reference_id on payment (if it points to a contribution)
    $referenceId = $payment['reference_id'] ?? NULL;
    if ($referenceId && ctype_digit((string) $referenceId)) {
      $contribution = Contribution::get(FALSE)
        ->addWhere('id', '=', (int) $referenceId)
        ->addWhere('is_test', 'IN', [TRUE, FALSE])
        ->addSelect('contact_id')
        ->execute()
        ->first();
      if (!empty($contribution)) {
        return (int) $contribution['contact_id'];
      }
    }

    // 3. Try via receipt email (if available)
    $receiptEmail = $payment['receipt_email'] ?? NULL;
    if ($receiptEmail) {
      $contact = Contact::get(FALSE)
        ->addWhere('email', '=', $receiptEmail)
        ->addSelect('id')
        ->execute()
        ->first();
      if (!empty($contact)) {
        return (int) $contact['id'];
      }
    }

    return NULL;
  }

  /**
   * Get webhook signature key from processor config.
   *
   * @return string|null
   */
  public function getWebhookSignatureKey() {
    if ($this->isTestMode()) {
      return $this->_paymentProcessor['test_subject'] ?? $this->_paymentProcessor['subject'] ?? NULL;
    }
    return $this->_paymentProcessor['subject'] ?? NULL;
  }

  /**
   * Handle subscription cancellation from Square webhook.
   *
   * @param array $payload
   *   Full webhook payload from Square.
   */
  public function handleSubscriptionCanceled(array $payload) {
    $this->handleSubscriptionCancelled($payload);
  }

  /**
   * Handle invoice paid from Square webhook.
   *
   * @param array $payload
   *   Full webhook payload from Square.
   */
  public function handleInvoicePaid(array $payload) {
    $this->handleInvoicePaymentCreated($payload);
  }

  /**
   * Handle subscription updated from Square webhook.
   *
   * @param array $payload
   *   Full webhook payload from Square.
   */
  public function handleSubscriptionUpdated(array $payload) {
    if (empty($payload['data']['object']['subscription']['id'])) {
      // CRM_Core_Payment_SquareDebugLogger::log('Square webhook: subscription.updated missing subscription ID.');.
      return;
    }

    $subscriptionId = $payload['data']['object']['subscription']['id'];
    $this->syncSubscriptionFromSquare($subscriptionId);
  }

  /**
   * Sync a Square subscription cancellation into CiviCRM.
   *
   * Called when Square sends subscription.canceled or subscription.deleted webhook.
   *
   * @param string $squareSubscriptionId
   *   The subscription ID from Square.
   *
   * @throws \CRM_Core_Exception
   */
  public function syncSubscriptionCancellationFromSquare($squareSubscriptionId) {
    CRM_Core_Payment_SquareDebugLogger::log("Square syncSubscriptionCancellationFromSquare(): called from SquareIPN for subscription_id={$squareSubscriptionId}.");
    if (empty($squareSubscriptionId)) {
      CRM_Core_Payment_SquareDebugLogger::log('Square syncSubscriptionCancellationFromSquare(): missing subscription ID, skipping.');
      return;
    }

    // Find the recurring contribution linked to this subscription.
    $recur = $this->findRecurBySubscriptionId($squareSubscriptionId);

    if (empty($recur)) {
      CRM_Core_Payment_SquareDebugLogger::log("Square syncSubscriptionCancellationFromSquare(): no recurring contribution found for subscription {$squareSubscriptionId}, skipping.");
      return;
    }

    $recurId = (int) $recur['id'];
    CRM_Core_Payment_SquareDebugLogger::log("Square syncSubscriptionCancellationFromSquare(): found contribution_recur {$recurId} (current status {$recur['contribution_status_id']}) for subscription {$squareSubscriptionId}, marking Cancelled.");

    ContributionRecur::update(FALSE)
      ->addWhere('id', '=', $recurId)
      ->addValue('contribution_status_id', $this->contributionStatusId('Cancelled'))
      ->execute();

    CRM_Core_Payment_SquareDebugLogger::log("Square syncSubscriptionCancellationFromSquare(): Updated recurring contribution {$recurId} to Cancelled for subscription {$squareSubscriptionId}.");
  }

  /**
   * Override CRM_Core_Payment function.
   *
   * @return array
   */
  public function getPaymentFormFields(): array {
    return [];
  }

  /**
   * Return an array of all the details about the fields potentially required for payment fields.
   *
   * Only those determined by getPaymentFormFields will actually be assigned to the form.
   *
   * @return array
   *   field metadata
   */
  public function getPaymentFormFieldsMetadata(): array {
    return [];
  }

  /**
   * Process incoming payment notification (IPN).
   *
   * Called by CiviCRM core when it receives a POST to:
   *   civicrm/payment/ipn/{processor_id}
   *
   * Validates the Square webhook signature, then delegates event processing
   * to CRM_Core_Payment_SquareIPN.
   */
  public function handlePaymentNotification() {
    http_response_code(200);
    $rawData = file_get_contents('php://input');

    if (!$this->validateWebhookSignature($rawData, getallheaders())) {
      Civi::log()->error('Square IPN: webhook signature validation failed.');
      http_response_code(401);
      exit();
    }

    $payload = json_decode($rawData, TRUE);
    if (empty($payload)) {
      Civi::log()->error('Square IPN: invalid JSON body received.');
      http_response_code(400);
      exit();
    }

    CRM_Core_Payment_SquareDebugLogger::log('Square IPN: handlePaymentNotification() received webhook. event_id=' . ($payload['event_id'] ?? 'unknown') . ', type=' . ($payload['type'] ?? 'unknown') . ', processor_id=' . $this->getID());

    $ipn = new CRM_Core_Payment_SquareIPN($this);
    $ipn->setData($rawData);
    if (!$ipn->onReceiveWebhook($payload)) {
      http_response_code(500);
    }
  }

  /**
   * Process a webhook record queued by CiviCRM's webhook worker. */
  public function processWebhookEvent(array $webhookEvent): bool {
    $ipn = new CRM_Core_Payment_SquareIPN($this);
    return $ipn->processQueuedWebhookEvent($webhookEvent);
  }

  /**
   * Validate the Square webhook HMAC-SHA256 signature.
   *
   * Square signs webhooks as:
   *   base64( HMAC-SHA256( notification_url + raw_body, signature_key ) )
   *
   * @param string $rawData
   *   Raw request body.
   * @param array $headers
   *   HTTP headers from getallheaders().
   *
   * @return bool
   */
  protected function validateWebhookSignature(string $rawData, array $headers): bool {
    $key = $this->getWebhookSignatureKey();
    if (!$key) {
      Civi::log()->error('Square IPN: webhook signature key not configured (check "Subject" field on payment processor).');
      return FALSE;
    }

    // Header keys are case-insensitive; normalise to lowercase.
    $normalised = [];
    foreach ($headers as $k => $v) {
      $normalised[strtolower($k)] = $v;
    }

    $provided = $normalised['x-square-hmacsha256-signature'] ?? NULL;
    if (!$provided) {
      Civi::log()->error('Square IPN: X-Square-Hmacsha256-Signature header missing.');
      return FALSE;
    }

    $notifyUrl = $this->getNotifyUrl();
    $expected = base64_encode(hash_hmac('sha256', $notifyUrl . $rawData, $key, TRUE));

    if (!hash_equals($expected, $provided)) {
      // Do not log signatures: they are credential-derived authentication data.
      Civi::log()->error('Square IPN: signature validation failed.');
      return FALSE;
    }

    return TRUE;
  }

}
