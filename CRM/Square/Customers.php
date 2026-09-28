<?php

use Civi\Api4\Contact;
use Civi\Api4\ContributionRecur;
use Civi\Api4\PaymentToken;
use Square\SquareClient;
use Square\Exceptions\SquareApiException;
use Square\Exceptions\SquareException;
use Square\Types\Address;
use Square\Types\Card;
use Square\Types\CustomerQuery;
use Square\Types\CustomerFilter;
use Square\Types\CustomerTextFilter;
use Square\Customers\Requests\CreateCustomerRequest;
use Square\Customers\Requests\UpdateCustomerRequest;
use Square\Customers\Requests\GetCustomersRequest;
use Square\Customers\Requests\SearchCustomersRequest;
use Square\Cards\Requests\CreateCardRequest;

/**
 * Square customers and cards on file for one payment processor.
 *
 * Maps CiviCRM contacts to Square customers (square_customer_map, scoped per
 * payment processor) and saves tokenized cards to the customer, recording
 * each as a CiviCRM PaymentToken.
 */
class CRM_Square_Customers {

  /**
   * Square API access for the payment processor.
   *
   * @var \CRM_Square_Gateway
   */
  protected CRM_Square_Gateway $gateway;

  /**
   * Builds the customer and card service for one payment processor.
   *
   * @param \CRM_Square_Gateway $gateway
   */
  public function __construct(CRM_Square_Gateway $gateway) {
    $this->gateway = $gateway;
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

    $response = $this->gateway->call(fn (SquareClient $client) => $client->customers->search(new SearchCustomersRequest([
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
  public function findSquareCustomerById($customerID) {
    if (empty($customerID)) {
      return NULL;
    }

    $response = $this->gateway->call(fn (SquareClient $client) => $client->customers->get(
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
  public function updateSquareCustomerDetails($customerID, $params) {
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

    $this->gateway->call(fn (SquareClient $client) => $client->customers->update(new UpdateCustomerRequest($requestValues)));
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
      $lookupResponse = $this->gateway->call(fn (SquareClient $client) => $client->customers->search(new SearchCustomersRequest([
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
          [1 => [$migratedCustomerId, 'String'], 2 => [$this->gateway->processorId(), 'Integer']]
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
        [1 => [$squareCustomerByEmail, 'String'], 2 => [$this->gateway->processorId(), 'Integer']]
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

    $createResponse = $this->gateway->call(fn (SquareClient $client) => $client->customers->create(new CreateCustomerRequest([
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
      'idempotencyKey' => $this->gateway->idempotencyKey('card', $cardNonce),
      'sourceId' => $cardNonce,
      'card' => new Card($cardValues),
    ];
    // Square requires verification_token for AVS/SCA under certain conditions.
    if (!empty($params['verification_token'])) {
      $requestValues['verificationToken'] = $params['verification_token'];
    }

    try {
      $response = $this->gateway->client()->cards->create(new CreateCardRequest($requestValues));
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
        ->addValue('payment_processor_id', $this->gateway->processorId())
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
   * Get the Square customer ID stored for a contact and processor.
   *
   * Live and sandbox accounts use distinct customer records.
   *
   * @param int $contactId
   *
   * @return string|null
   */
  public function getSquareCustomerId($contactId) {
    $contactId = (int) $contactId;
    $processorId = $this->gateway->processorId();
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
    $processorId = $this->gateway->processorId();
    if ($contactId <= 0 || $processorId <= 0 || empty($customerId)) {
      return;
    }

    // An existing mapping is never re-pointed (e.g. by two concurrent
    // checkouts for the same contact): the insert is a no-op if the contact
    // already has a customer, or the customer already has a contact
    // (square_customer_map's two unique keys), and the conflict is logged.
    CRM_Core_DAO::executeQuery(
      'INSERT INTO square_customer_map (contact_id, payment_processor_id, square_customer_id)
       VALUES (%1, %2, %3)
       ON DUPLICATE KEY UPDATE square_customer_id = square_customer_id',
      [1 => [$contactId, 'Integer'], 2 => [$processorId, 'Integer'], 3 => [$customerId, 'String']]
    );
    $mappedCustomerId = $this->getSquareCustomerId($contactId);
    if ($mappedCustomerId === NULL) {
      Civi::log()->warning("Square: Square customer {$customerId} is already mapped to another contact on payment processor {$processorId}; not mapping it to contact {$contactId}.");
    }
    elseif ($mappedCustomerId !== (string) $customerId) {
      Civi::log()->warning("Square: contact {$contactId} is already mapped to Square customer {$mappedCustomerId} on payment processor {$processorId}; not re-mapping it to {$customerId}.");
    }
  }

}
