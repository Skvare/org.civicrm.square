<?php

use Square\SquareClient;
use Square\Locations\Requests\GetLocationsRequest;

/**
 * The Square location a payment processor takes payments at.
 *
 * A Square location takes payments in one currency only, and Square refuses
 * a payment in any other. Looking the location up lets CiviCRM refuse such a
 * payment itself, with a clear message, and the system check warn about it
 * before anyone tries to pay.
 */
class CRM_Square_Locations {

  /**
   * How long, in seconds, a location's details are cached.
   *
   * A location's currency follows its country, which a seller cannot
   * change, so this only bounds how long a renamed or deactivated location
   * takes to show.
   */
  public const CACHE_TTL = 6 * 3600;

  /**
   * Square API access for the payment processor.
   *
   * @var \CRM_Square_Gateway
   */
  protected CRM_Square_Gateway $gateway;

  /**
   * Builds the location lookup for one payment processor.
   *
   * @param \CRM_Square_Gateway $gateway
   */
  public function __construct(CRM_Square_Gateway $gateway) {
    $this->gateway = $gateway;
  }

  /**
   * The processor's Square location.
   *
   * @param bool $refresh
   *   Ask Square even if the location is cached.
   *
   * @return array
   *   id, name, status (ACTIVE or INACTIVE), currency, timezone and
   *   merchant_id.
   *
   * @throws \CRM_Core_Exception
   *   If the location is not configured, or Square cannot be asked or
   *   refuses (e.g. the access token is wrong, or the location belongs to
   *   another account).
   */
  public function get(bool $refresh = FALSE): array {
    $locationId = $this->gateway->getLocationId();
    $cacheKey = 'square_location_' . hash('sha256', implode(':', [
      $this->gateway->processorId(),
      $this->gateway->isTestMode() ? 'test' : 'live',
      $locationId,
    ]));
    if (!$refresh) {
      $cached = Civi::cache('long')->get($cacheKey);
      if (is_array($cached)) {
        return $cached;
      }
    }

    $location = $this->gateway->call(fn (SquareClient $client) => $client->locations->get(new GetLocationsRequest(['locationId' => $locationId])))->getLocation();
    if (empty($location)) {
      throw new CRM_Core_Exception("Square location {$locationId} was not found.");
    }
    $details = [
      'id' => (string) $location->getId(),
      'name' => (string) $location->getName(),
      'status' => strtoupper((string) $location->getStatus()),
      'currency' => strtoupper((string) $location->getCurrency()),
      'timezone' => (string) $location->getTimezone(),
      'merchant_id' => (string) $location->getMerchantId(),
    ];
    Civi::cache('long')->set($cacheKey, $details, self::CACHE_TTL);
    return $details;
  }

  /**
   * The currency the processor's Square location takes payments in.
   *
   * @return string|null
   *   ISO 4217 code, or NULL if Square does not say.
   *
   * @throws \CRM_Core_Exception
   */
  public function getCurrency(): ?string {
    $currency = $this->get()['currency'];
    return $currency === '' ? NULL : $currency;
  }

}
