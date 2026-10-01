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
   * Ensure a Square customer exists for this contact, saving any submitted card.
   *
   * The customer is, in order: the one already mapped to the contact for
   * this payment processor; an existing Square customer adopted for it (see
   * adoptSquareCustomer()); or a new one.
   *
   * @param array $params
   *   Contribution params (includes contactID/contact_id, and the card
   *   token as square_payment_token).
   *
   * @return string
   *   Square customer ID.
   *
   * @throws CRM_Core_Exception
   */
  public function ensureSquareCustomer(array $params) {
    $contactID = (int) ($params['contactID'] ?? $params['contact_id'] ?? 0);
    if (!$contactID) {
      throw new CRM_Core_Exception('Missing contactID in params for Square recurring payment.');
    }

    $customerId = $this->getSquareCustomerId($contactID)
      ?? $this->adoptSquareCustomer($contactID)
      ?? $this->createSquareCustomer($contactID);
    CRM_Core_Payment_SquareDebugLogger::log("Square customer for contact {$contactID}: {$customerId}");

    // Card nonces are single-use, so this must be the only place that
    // redeems it (e.g. a returning donor entering a new card).
    $cardNonce = self::cardNonce($params);
    if ($cardNonce) {
      $this->createCardOnFile($customerId, $cardNonce, $params, $contactID);
    }
    return $customerId;
  }

  /**
   * The card token submitted with the payment, if any.
   *
   * @param array $params
   *
   * @return string|null
   */
  public static function cardNonce(array $params): ?string {
    $nonce = $params['square_payment_token'] ?? $params['payment_token'] ?? $params['token'] ?? NULL;
    return empty($nonce) ? NULL : (string) $nonce;
  }

  /**
   * Map an existing Square customer to a contact that has none for this processor.
   *
   * Candidates are Square customers whose reference_id is the contact's ID
   * (as this extension sets it), then those with the contact's email. A
   * candidate already mapped to another contact on this processor is never
   * shared: family members often share one email address, and each gets a
   * Square customer of their own.
   *
   * @param int $contactID
   *
   * @return string|null
   *   The Square customer ID now mapped to the contact, or NULL if there
   *   was none to adopt.
   *
   * @throws CRM_Core_Exception
   */
  protected function adoptSquareCustomer(int $contactID): ?string {
    $candidates = $this->searchSquareCustomers(new CustomerFilter([
      'referenceId' => new CustomerTextFilter(['exact' => (string) $contactID]),
    ]));
    $email = $this->getContactDetails($contactID)['email'] ?? NULL;
    if ($email) {
      $candidates = array_merge($candidates, $this->searchSquareCustomers(new CustomerFilter([
        'emailAddress' => new CustomerTextFilter(['exact' => $email]),
      ])));
    }

    foreach (array_unique($candidates) as $candidateId) {
      $mappedContactId = $this->getMappedContactId($candidateId);
      if ($mappedContactId !== NULL && $mappedContactId !== $contactID) {
        continue;
      }
      $mapped = $this->saveSquareCustomerId($contactID, $candidateId);
      if ($mapped !== NULL) {
        CRM_Core_Payment_SquareDebugLogger::log("Square: adopted existing Square customer {$mapped} for contact {$contactID}.");
        return $mapped;
      }
    }
    return NULL;
  }

  /**
   * Create a Square customer for a contact, and map it to the contact.
   *
   * @param int $contactID
   *
   * @return string
   *   The Square customer ID mapped to the contact.
   *
   * @throws CRM_Core_Exception
   */
  protected function createSquareCustomer(int $contactID): string {
    $contact = $this->getContactDetails($contactID);
    if (empty($contact)) {
      throw new CRM_Core_Exception("Unable to load contact {$contactID} for Square customer creation.");
    }

    $createResponse = $this->gateway->call(fn (SquareClient $client) => $client->customers->create(new CreateCustomerRequest([
      'givenName' => $contact['first_name'] ?: NULL,
      'familyName' => $contact['last_name'] ?: NULL,
      'emailAddress' => $contact['email'] ?: NULL,
      'referenceId' => (string) $contactID,
    ])));
    $newCustomer = $createResponse->getCustomer();
    if (empty($newCustomer) || empty($newCustomer->getId())) {
      throw new CRM_Core_Exception('Failed to create Square customer.');
    }

    // A concurrent checkout for the same contact may have mapped one first.
    $mapped = $this->saveSquareCustomerId($contactID, $newCustomer->getId());
    if ($mapped === NULL) {
      throw new CRM_Core_Exception("Square customer {$newCustomer->getId()} could not be mapped to contact {$contactID}.");
    }
    return $mapped;
  }

  /**
   * IDs of the Square customers matching a filter.
   *
   * @param \Square\Types\CustomerFilter $filter
   *
   * @return string[]
   *
   * @throws CRM_Core_Exception
   */
  protected function searchSquareCustomers(CustomerFilter $filter): array {
    $response = $this->gateway->call(fn (SquareClient $client) => $client->customers->search(new SearchCustomersRequest([
      'query' => new CustomerQuery(['filter' => $filter]),
    ])));
    $ids = [];
    foreach ($response->getCustomers() ?? [] as $customer) {
      if ($customer->getId()) {
        $ids[] = $customer->getId();
      }
    }
    return $ids;
  }

  /**
   * A contact's name and primary email.
   *
   * @param int $contactID
   *
   * @return array|null
   *   first_name, last_name and email; NULL if there is no such contact.
   */
  protected function getContactDetails(int $contactID): ?array {
    $contact = Contact::get(FALSE)
      ->addWhere('id', '=', $contactID)
      ->addSelect('first_name', 'last_name', 'email_primary.email')
      ->execute()
      ->first();
    if (!$contact) {
      return NULL;
    }
    return [
      'first_name' => $contact['first_name'] ?? NULL,
      'last_name' => $contact['last_name'] ?? NULL,
      'email' => $contact['email_primary.email'] ?? NULL,
    ];
  }

  /**
   * Attach a card to the Square customer using the tokenized card nonce.
   *
   * @param string $customerId
   *   Square customer ID.
   * @param string $cardNonce
   *   Token from Web Payments SDK.
   * @param array $params
   *   Payment params: billing address fields, names, email, and
   *   contributionRecurID.
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
    $billingAddress = $this->billingAddress($params);
    if ($billingAddress) {
      $cardValues['billingAddress'] = $billingAddress;
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

    // No verificationToken: buyer verification (Strong Customer
    // Authentication) happens when js/square.js tokenizes the card, so the
    // token itself carries it.
    try {
      $response = $this->gateway->client()->cards->create(new CreateCardRequest($requestValues));
    }
    catch (SquareApiException $e) {
      // Keep the friendly message and the gateway's payment-failure type so
      // recurring checkout runs CiviCRM's cleanup when the card is declined.
      throw $this->gateway->apiError($e, $this->translateSquareCardError($e->getErrors()));
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
   * The card's billing address, from the checkout's billing fields.
   *
   * CiviCRM submits them as billing_street_address-{location type ID} etc.;
   * its checkout also maps them to street_address, city, state_province (an
   * abbreviation), postal_code and country (an ISO code).
   *
   * @param array $params
   *
   * @return \Square\Types\Address|null
   *   NULL if no address was submitted.
   */
  protected function billingAddress(array $params): ?Address {
    $value = static function (string $field) use ($params): ?string {
      if (isset($params[$field]) && is_scalar($params[$field]) && trim((string) $params[$field]) !== '') {
        return trim((string) $params[$field]);
      }
      foreach ($params as $key => $fieldValue) {
        if (is_scalar($fieldValue) && trim((string) $fieldValue) !== '' && preg_match('/^billing_' . preg_quote($field, '/') . '-\d+$/', (string) $key)) {
          return trim((string) $fieldValue);
        }
      }
      return NULL;
    };

    $street = $value('street_address');
    $city = $value('city');
    $postalCode = $value('postal_code');
    if ($street === NULL && $city === NULL && $postalCode === NULL) {
      return NULL;
    }

    $state = $value('state_province');
    $stateId = $value('state_province_id');
    if ($stateId && ctype_digit($stateId)) {
      $state = CRM_Core_PseudoConstant::stateProvinceAbbreviation((int) $stateId) ?: $state;
    }
    $country = $value('country');
    $countryId = $value('country_id');
    if ($countryId && ctype_digit($countryId)) {
      $country = CRM_Core_PseudoConstant::countryIsoCode()[(int) $countryId] ?? $country;
    }
    // Square wants an ISO 3166 alpha-2 code; send none rather than a guess.
    $country = ($country !== NULL && preg_match('/^[A-Za-z]{2}$/', $country)) ? strtoupper($country) : NULL;

    return new Address(array_filter([
      'addressLine1' => $street,
      'addressLine2' => $value('supplemental_address_1'),
      'locality' => $city,
      'administrativeDistrictLevel1' => $state,
      'postalCode' => $postalCode,
      'country' => $country,
    ], fn ($fieldValue) => $fieldValue !== NULL));
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
   * The contact a Square customer is mapped to on this processor, if any.
   *
   * @param string $customerId
   *
   * @return int|null
   */
  protected function getMappedContactId(string $customerId): ?int {
    $contactId = CRM_Core_DAO::singleValueQuery(
      'SELECT contact_id FROM square_customer_map WHERE square_customer_id = %1 AND payment_processor_id = %2',
      [1 => [$customerId, 'String'], 2 => [$this->gateway->processorId(), 'Integer']]
    );
    return $contactId === NULL ? NULL : (int) $contactId;
  }

  /**
   * Save the Square customer ID for a contact and processor.
   *
   * See getSquareCustomerId().
   *
   * @param int $contactId
   * @param string $customerId
   *
   * @return string|null
   *   The customer the contact is mapped to afterwards — another one if the
   *   contact already had one — or NULL if $customerId belongs to another
   *   contact, so the contact still has none.
   */
  protected function saveSquareCustomerId($contactId, $customerId): ?string {
    $contactId = (int) $contactId;
    $processorId = $this->gateway->processorId();
    if ($contactId <= 0 || $processorId <= 0 || empty($customerId)) {
      return NULL;
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
      Civi::log('square')->warning("Square: Square customer {$customerId} is already mapped to another contact on payment processor {$processorId}; not mapping it to contact {$contactId}.");
    }
    elseif ($mappedCustomerId !== (string) $customerId) {
      Civi::log('square')->warning("Square: contact {$contactId} is already mapped to Square customer {$mappedCustomerId} on payment processor {$processorId}; not re-mapping it to {$customerId}.");
    }
    return $mappedCustomerId;
  }

}
