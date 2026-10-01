<?php

use CRM_Square_ExtensionUtil as E;
use Civi\Api4\ContributionRecur;
use Civi\Api4\Payment;
use Civi\Api4\PaymentToken;
use Civi\Payment\Exception\PaymentProcessorException;
use Civi\Payment\PropertyBag;
use Square\SquareClient;
use Square\Types\Money;
use Square\Types\SubscriptionSource;
use Square\Payments\Requests\CreatePaymentRequest;
use Square\Subscriptions\Requests\CreateSubscriptionRequest;
use Square\Refunds\Requests\RefundPaymentRequest;

require_once E::path() . '/vendor/autoload.php';
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
 *
 * This class is CiviCRM's adapter: it implements the CRM_Core_Payment
 * contract and delegates the work to
 *  - CRM_Square_Gateway: Square API access (client, errors, idempotency);
 *  - CRM_Square_Customers: Square customers and cards on file;
 *  - CRM_Square_Subscriptions: catalog plans and subscriptions;
 *  - CRM_Square_Reconciler: webhook-driven sync into CiviCRM's ledger.
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
      // Never fall back to any other credential: everything here is
      // published to the browser.
      'locationId' => trim((string) ($this->_paymentProcessor['signature'] ?? '')),
      'isSandbox' => (bool) $isSandbox,
      // For the buyer verification details js/square.js passes to Square
      // when tokenizing the card.
      'currency' => (method_exists($form, 'getCurrency') ? $form->getCurrency() : NULL) ?: 'USD',
      'countryIsoCodes' => (object) CRM_Core_PseudoConstant::countryIsoCode(),
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
    return $this->gateway()->createClient();
  }

  /**
   * Square API access for this processor.
   *
   * Its client comes from buildSquareClient(), so overriding that replaces
   * the client every collaborator uses.
   *
   * @return \CRM_Square_Gateway
   */
  protected function gateway(): CRM_Square_Gateway {
    return new CRM_Square_Gateway($this->_paymentProcessor, fn () => $this->buildSquareClient());
  }

  /**
   * Square customers and cards on file for this processor.
   *
   * @return \CRM_Square_Customers
   */
  protected function customers(): CRM_Square_Customers {
    return new CRM_Square_Customers($this->gateway());
  }

  /**
   * Square subscription plans and subscriptions for this processor.
   *
   * @return \CRM_Square_Subscriptions
   */
  protected function subscriptions(): CRM_Square_Subscriptions {
    return new CRM_Square_Subscriptions($this->gateway());
  }

  /**
   * Webhook-driven sync of Square activity into CiviCRM for this processor.
   *
   * @return \CRM_Square_Reconciler
   */
  protected function reconciler(): CRM_Square_Reconciler {
    return new CRM_Square_Reconciler($this->gateway());
  }

  /**
   * Build a retry-stable idempotency key unique to each processor and environment.
   */
  protected function idempotencyKey(string $operation, string $reference): string {
    return $this->gateway()->idempotencyKey($operation, $reference);
  }

  /**
   * Invoke the Square SDK client, translating its exceptions to CRM_Core_Exception.
   *
   * @param callable(\Square\SquareClient): mixed $call
   *
   * @return mixed
   *   Whatever $call returns (typically a Square SDK response object).
   *
   * @throws \CRM_Core_Exception
   */
  protected function callSquare(callable $call) {
    return $this->gateway()->call($call);
  }

  /**
   * Get the Square Location ID from processor config.
   *
   * @return string
   *
   * @throws \CRM_Core_Exception
   */
  protected function getLocationId() {
    return $this->gateway()->getLocationId();
  }

  /**
   * ID of this payment processor (civicrm_payment_processor.id).
   */
  protected function processorId(): int {
    return $this->gateway()->processorId();
  }

  /**
   * Check every Square credential this processor needs is configured.
   *
   * @return string|null
   *   An error message naming the missing ones, or NULL.
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
   * @param array $payment
   *   Payment object from Square webhooks.
   *
   * @see CRM_Square_Reconciler::syncPaymentFromSquare()
   */
  public function syncPaymentFromSquare(array $payment) {
    $this->reconciler()->syncPaymentFromSquare($payment);
  }

  /**
   * Sync a Square refund (refund.created / refund.updated) into CiviCRM.
   *
   * @param array $refund
   *
   * @see CRM_Square_Reconciler::syncRefundFromSquare()
   */
  public function syncRefundFromSquare(array $refund) {
    $this->reconciler()->syncRefundFromSquare($refund);
  }

  /**
   * Sync a paid Square subscription invoice into CiviCRM.
   *
   * @param array $invoice
   *
   * @see CRM_Square_Reconciler::syncInvoiceFromSquare()
   */
  public function syncInvoiceFromSquare(array $invoice) {
    $this->reconciler()->syncInvoiceFromSquare($invoice);
  }

  /**
   * Apply a Square subscription's current status and amount to its recurring contribution.
   *
   * @param string $squareSubscriptionId
   *
   * @see CRM_Square_Reconciler::syncSubscriptionFromSquare()
   */
  public function syncSubscriptionFromSquare(string $squareSubscriptionId) {
    $this->reconciler()->syncSubscriptionFromSquare($squareSubscriptionId);
  }

  /**
   * Process Square invoice.payment_made webhook event.
   *
   * @param array $payload
   *   Full decoded JSON from Square webhook.
   *
   * @see CRM_Square_Reconciler::handleInvoicePaymentCreated()
   */
  public function handleInvoicePaymentCreated(array $payload) {
    $this->reconciler()->handleInvoicePaymentCreated($payload);
  }

  /**
   * Sync a Square invoice.scheduled_charge_failed webhook event.
   *
   * @param array $invoice
   *
   * @see CRM_Square_Reconciler::syncInvoicePaymentFailedFromSquare()
   */
  public function syncInvoicePaymentFailedFromSquare(array $invoice): void {
    $this->reconciler()->syncInvoicePaymentFailedFromSquare($invoice);
  }

  /**
   * Ensure a Square customer exists for this contact, saving any submitted card.
   *
   * @param array $params
   *   Contribution params (includes contactID/contact_id).
   *
   * @return string
   *   Square customer ID.
   *
   * @see CRM_Square_Customers::ensureSquareCustomer()
   */
  public function ensureSquareCustomer(array $params) {
    return $this->customers()->ensureSquareCustomer($params);
  }

  /**
   * Attach a card to the Square customer using the tokenized card nonce.
   *
   * @param string $customerId
   * @param string $cardNonce
   * @param array $params
   * @param int|null $contactId
   *
   * @return string
   *   Square card ID.
   *
   * @see CRM_Square_Customers::createCardOnFile()
   */
  public function createCardOnFile($customerId, $cardNonce, array $params = [], $contactId = NULL) {
    return $this->customers()->createCardOnFile($customerId, $cardNonce, $params, $contactId);
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
    try {
      // Determine if this is a recurring payment.
      if (!empty($params['is_recur']) || !empty($params['contributionRecurID'])) {
        return $this->doRecurPayment($params);
      }
      return $this->doOneTimePayment($params);
    }
    catch (PaymentProcessorException $e) {
      // Square declined the card: CiviCRM's checkout cleans up.
      throw $e;
    }
    catch (CRM_Core_Payment_SquareOutcomeUnknownException $e) {
      // Already logged. A contribution page keeps its Pending contribution
      // for the webhook to complete; event registration only handles
      // PaymentProcessorException, and has no contribution yet.
      if ($component === 'event') {
        throw new PaymentProcessorException($e->getMessage(), 0, [], $e);
      }
      throw $e;
    }
    catch (\Throwable $e) {
      // Anything else happened before Square could charge the card (or
      // Square rejected the request), so nothing was taken: report it as a
      // failed payment, which CiviCRM's checkout knows how to clean up.
      \Civi::log('square')->error('Square checkout failed: ' . $e->getMessage(), ['exception' => $e]);
      throw new PaymentProcessorException(E::ts('Your payment could not be processed. Please try again, or contact us if the problem continues.'), 0, [], $e);
    }
  }

  /**
   * The Square Web Payments SDK card token submitted with the billing form.
   *
   * Webform CiviCRM's confirm-form path does not always merge $_POST values
   * into payment params, so the request is the fallback.
   *
   * @param array $params
   *
   * @return string|null
   */
  protected function getPaymentToken(array $params): ?string {
    $token = CRM_Square_Customers::cardNonce($params)
      ?? $_POST['square_payment_token']
      ?? $_REQUEST['square_payment_token']
      ?? NULL;
    return empty($token) ? NULL : (string) $token;
  }

  /**
   * Log, and describe to the payer, a charge Square may have made without confirming it.
   *
   * @param \Throwable $e
   * @param string $reference
   *   What was being paid, for the log.
   *
   * @return \CRM_Core_Payment_SquareOutcomeUnknownException
   */
  protected function paymentOutcomeUnknown(\Throwable $e, string $reference): CRM_Core_Payment_SquareOutcomeUnknownException {
    \Civi::log('square')->error("Square: could not confirm the outcome of {$reference} on payment processor {$this->processorId()}; check the Square Dashboard before the payer tries again. " . $e->getMessage(), ['exception' => $e]);
    return new CRM_Core_Payment_SquareOutcomeUnknownException(
      E::ts('We could not confirm whether your payment went through. Please do not pay again yet: contact us so we can check.'),
      0, [], $e
    );
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
      $completedStatusId = CRM_Square_Status::contributionStatusId('Completed');
      $params['payment_status_id'] = $completedStatusId;
      $params['payment_status'] = 'Completed';
      $params['contribution_status_id'] = $completedStatusId;
      return $params;
    }

    // 2. Extract Web Payments SDK token.
    $token = $this->getPaymentToken($params);
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
      $customerId = $this->customers()->getSquareCustomerId($contactId);
      if ($customerId) {
        $requestValues['customerId'] = $customerId;
      }
    }

    // Optional reference.
    if (!empty($params['invoiceID'])) {
      $requestValues['referenceId'] = (string) $params['invoiceID'];
    }

    // 5. Send request. A decline or rejected request means nothing was
    // charged; a server error, timeout or unreadable response does not.
    try {
      $response = $this->callSquare(fn (SquareClient $client) => $client->payments->create(new CreatePaymentRequest($requestValues)));
    }
    catch (CRM_Core_Payment_SquareRetryableException $e) {
      throw $this->paymentOutcomeUnknown($e, "payment {$paymentReference}");
    }

    // 6. Report the outcome; CiviCRM's checkout records the payment against
    // the returned trxn_id. A payment Square has authorized but not yet
    // settled is reported Pending — payment.updated completes it later.
    // Square has accepted the charge by now, so no failure from here on
    // may be reported as a failed payment.
    try {
      $payment = $response->getPayment();
      if (empty($payment) || empty($payment->getId())) {
        throw new \CRM_Core_Exception('Square payment response has no payment ID.');
      }
      $isCompleted = CRM_Square_Status::mapPaymentStatus($payment->getStatus() ?? 'UNKNOWN') === CRM_Square_Status::contributionStatusId('Completed');
      $statusName = $isCompleted ? 'Completed' : 'Pending';
      $params['trxn_id'] = $payment->getId();
      $params['payment_status_id'] = CRM_Square_Status::contributionStatusId($statusName);
      $params['payment_status'] = $statusName;
      $params['contribution_status_id'] = $params['payment_status_id'];
    }
    catch (\Throwable $e) {
      throw $this->paymentOutcomeUnknown($e, "payment {$paymentReference}");
    }

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
    $token = $this->getPaymentToken($params);
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

    // 3. Determine the plan variation first: an amount or cadence Square
    // cannot bill is refused before any customer or card is saved.
    $planVariationId = $this->subscriptions()->getPlanVariationIdForParams($params);
    CRM_Core_Payment_SquareDebugLogger::log("Square doRecurPayment: Using plan variation ID {$planVariationId} for CiviCRM recur ID {$recurId}");

    // 4. Ensure the customer exists (saving the card), with current details.
    $customers = $this->customers();
    $customerId = $customers->ensureSquareCustomer($params);
    $customers->updateSquareCustomerDetails($customerId, $params);

    // 5. Fetch the Square card ID via the PaymentToken that
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

    // 6. Generate idempotency key tied to the recurring record so re-posts don't duplicate.
    $idempotencyKey = $this->idempotencyKey('subscription', (string) $recurId);
    $source = [
      'contact_id' => (string) ($params['contactID'] ?? $params['contact_id'] ?? ''),
      'recur_id' => (string) $recurId,
    ];
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

    // 8. Send subscription create request. Square bills the first
    // installment as soon as the subscription exists, so once this may have
    // succeeded, no failure may be reported as a failed payment.
    try {
      $response = $this->callSquare(fn (SquareClient $client) => $client->subscriptions->create($createSubscriptionRequest));
    }
    catch (CRM_Core_Payment_SquareRetryableException $e) {
      throw $this->paymentOutcomeUnknown($e, "subscription for recurring contribution {$recurId}");
    }
    try {
      $subscription = $response->getSubscription();
      if (empty($subscription) || empty($subscription->getId())) {
        throw new CRM_Core_Exception('Square subscription response has no subscription ID.');
      }
      $subscriptionId = $subscription->getId();
      CRM_Core_Payment_SquareDebugLogger::log('Square subscription created: ' . json_encode([
        'subscription_id' => $subscriptionId,
        'recur_id' => $recurId,
      ]));

      // 9. Link the subscription to the recurring contribution, which stays
      // Pending until its first payment is recorded.
      $this->saveRecurSubscription((int) $recurId, $subscriptionId);
    }
    catch (\Throwable $e) {
      $reference = "subscription " . ($subscriptionId ?? '(unknown ID)') . " for recurring contribution {$recurId}";
      throw $this->paymentOutcomeUnknown($e, $reference);
    }

    // 10. The checkout's contribution stays Pending until Square confirms
    // the first charge. No trxn_id is returned: the subscription ID
    // identifies the recurring agreement (saved above as the recur's
    // processor_id), not a payment, and webform_civicrm writes a returned
    // trxn_id straight onto the Pending contribution. Its trxn_id becomes
    // the Square payment ID when that payment is recorded.
    $params['trxn_id'] = NULL;
    $pendingStatusId = CRM_Square_Status::contributionStatusId('Pending');
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
      ->addValue('contribution_status_id', CRM_Square_Status::recurStatusId('Pending'))
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
   *   trxn_id (the Square payment ID) and amount; currency is optional, and
   *   ignored in favour of the payment's own.
   *
   * @return array
   *   refund_trxn_id (the Square refund ID), refund_status ('Completed', as
   *   CiviCRM and mjwshared's refund form expect), trxn_date and fee_amount.
   *
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   */
  public function doRefund(&$params) {
    $trxnId = $params['trxn_id'] ?? $params['transaction_id'] ?? NULL;
    if (empty($trxnId)) {
      throw new PaymentProcessorException('Missing transaction ID for refund.');
    }

    if (empty($params['amount'])) {
      throw new PaymentProcessorException('Missing refund amount.');
    }

    $rawAmount = (float) $params['amount'];
    $amountInCents = (int) round($rawAmount * 100);
    if ($amountInCents <= 0) {
      throw new PaymentProcessorException('Refund amount must be greater than zero.');
    }

    try {
      // The refund must be in the payment's currency, which mjwshared's
      // refund form does not always pass on.
      [$paymentCurrency, $refundCount] = $this->getRefundContext((string) $trxnId);
      $currency = $paymentCurrency ?? $params['currencyID'] ?? $params['currency'] ?? 'USD';

      // The key changes once CiviCRM has recorded a refund, so a further
      // refund of the same amount is a new refund — not mistaken by Square
      // for a retry of the first, which would return the first again. Until
      // then it does not change, so a retry of a refund whose outcome was
      // lost gets Square's original refund back instead of a second one.
      $refundRequest = new RefundPaymentRequest([
        'idempotencyKey' => $this->idempotencyKey('refund', "{$trxnId}:{$amountInCents}:{$refundCount}"),
        'paymentId' => $trxnId,
        'amountMoney' => new Money(['amount' => $amountInCents, 'currency' => $currency]),
      ]);

      $refund = $this->createRefund($refundRequest)->getRefund();
    }
    catch (PaymentProcessorException $e) {
      throw $e;
    }
    catch (\Throwable $e) {
      throw new PaymentProcessorException(E::ts('Square refund failed: %1', [1 => $e->getMessage()]), 0, [], $e);
    }

    if (empty($refund) || empty($refund->getId())) {
      throw new PaymentProcessorException('Square refund failed: unexpected response.');
    }

    $status = strtoupper($refund->getStatus() ?? 'UNKNOWN');
    // Square accepts a refund as PENDING and completes it moments later (or,
    // rarely, rejects it: CRM_Square_Reconciler::syncRefundFromSquare() logs
    // that for follow-up). It is reported Completed so CiviCRM records it
    // now; the refund.* webhooks find it by its ID and record nothing more.
    if (!in_array($status, ['PENDING', 'COMPLETED', 'APPROVED'], TRUE)) {
      throw new PaymentProcessorException("Square refund not completed. Status: {$status}");
    }

    $createdAt = $refund->getCreatedAt();
    return [
      'refund_trxn_id' => $refund->getId(),
      'refund_status' => 'Completed',
      'trxn_date' => date('Y-m-d H:i:s', $createdAt && strtotime($createdAt) ? strtotime($createdAt) : time()),
      // Square reports the refunded processing fee later, if at all.
      'fee_amount' => 0,
    ];
  }

  /**
   * The currency of a payment recorded in CiviCRM, and how many refunds its contribution has.
   *
   * @param string $paymentTrxnId
   *   The Square payment ID.
   *
   * @return array
   *   [currency or NULL if the payment is not recorded, number of refunds].
   */
  protected function getRefundContext(string $paymentTrxnId): array {
    $payment = Payment::get(FALSE)
      ->addSelect('contribution_id', 'currency')
      ->addWhere('trxn_id', '=', $paymentTrxnId)
      ->addWhere('total_amount', '>', 0)
      ->execute()
      ->first();
    if (empty($payment['contribution_id'])) {
      return [NULL, 0];
    }
    $refundCount = Payment::get(FALSE)
      ->addSelect('id')
      ->addWhere('contribution_id', '=', $payment['contribution_id'])
      ->addWhere('total_amount', '<', 0)
      ->execute()
      ->count();
    return [$payment['currency'] ?? NULL, $refundCount];
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
   * Change the amount of a Square subscription.
   *
   * CiviCRM's contract for "change the amount of a recurring contribution"
   * (CRM_Contribute_Form_UpdateSubscription): its existence enables that
   * form's amount field. After this returns, CiviCRM saves the submitted
   * amount and installments on the recurring contribution.
   *
   * @param string $message
   *   Activity details for the change; unchanged here.
   * @param array $params
   *   From the form: subscriptionId (the recur's processor_id),
   *   contributionRecurID, amount and installments.
   *
   * @return bool
   *
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   */
  public function changeSubscriptionAmount(&$message = '', $params = []) {
    $subscriptionId = $params['subscriptionId'] ?? NULL;
    $recurId = (int) ($params['contributionRecurID'] ?? $params['id'] ?? 0);
    // The form passes the amount as submitted, e.g. "1,000.00".
    $amount = isset($params['amount']) ? CRM_Utils_Rule::cleanMoney((string) $params['amount']) : NULL;
    if (empty($subscriptionId) || !$recurId || !is_numeric($amount) || (float) $amount <= 0) {
      throw new PaymentProcessorException(E::ts('A Square subscription and a positive amount are required to change the amount.'));
    }

    $recur = $this->getRecurForAmountChange($recurId);
    if (!$recur || $recur['processor_id'] !== $subscriptionId) {
      throw new PaymentProcessorException(E::ts('This recurring contribution is not linked to that Square subscription.'));
    }
    // CiviCRM also saves the form's installments; Square cannot change the
    // number of billing periods of an existing subscription.
    if (isset($params['installments']) && (int) $params['installments'] !== (int) ($recur['installments'] ?? 0)) {
      throw new PaymentProcessorException(E::ts('Square cannot change the number of installments of an existing subscription. Cancel it and create a new recurring contribution instead.'));
    }
    // CiviCRM only moves a single-line-item template contribution to the
    // new amount, so later installments of a multi-item series would keep
    // the old one — and never match what Square charges.
    if ($recur['line_item_count'] > 1) {
      throw new PaymentProcessorException(E::ts('The amount of a recurring contribution with more than one line item cannot be changed.'));
    }

    try {
      $this->subscriptions()->changeAmount($subscriptionId, (float) $amount, $recur['currency']);
    }
    catch (\Throwable $e) {
      throw new PaymentProcessorException(E::ts('Failed to change the Square subscription amount: %1', [1 => $e->getMessage()]), 0, [], $e);
    }
    return TRUE;
  }

  /**
   * The recurring contribution changeSubscriptionAmount() is changing.
   *
   * @param int $recurId
   *
   * @return array|null
   *   processor_id, installments, currency, and line_item_count (of the
   *   template contribution later installments are copied from). NULL if
   *   the recurring contribution does not belong to this processor.
   */
  protected function getRecurForAmountChange(int $recurId): ?array {
    $recur = ContributionRecur::get(FALSE)
      ->addSelect('processor_id', 'installments', 'currency')
      ->addWhere('id', '=', $recurId)
      ->addWhere('payment_processor_id', '=', $this->processorId())
      ->execute()
      ->first();
    if (!$recur) {
      return NULL;
    }
    // Flattened: otherwise line_item is keyed by price set, one entry per set.
    $template = CRM_Contribute_BAO_ContributionRecur::getTemplateContribution($recurId, [], TRUE);
    $recur['line_item_count'] = count($template['line_item'] ?? []);
    return $recur;
  }

  /**
   * Cancel a Square subscription by its processor ID.
   *
   * Low-level helper used by doCancelRecurring(). Makes the Square API call
   * directly without PropertyBag logic.
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

    $this->subscriptions()->cancel($subscriptionId);
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

    // Returning ends the request: CRM_Core_Payment::handleIPN() calls
    // CRM_Utils_System::civiExit() next.
    if (!$this->validateWebhookSignature($rawData, getallheaders())) {
      // Already logged.
      http_response_code(401);
      return;
    }

    $payload = json_decode($rawData, TRUE);
    if (empty($payload)) {
      Civi::log()->error('Square IPN: invalid JSON body received.');
      http_response_code(400);
      return;
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
