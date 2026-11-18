<?php

namespace Civi\Api4;

use Civi\Api4\Generic\DAOEntity;

/**
 * The mjwshared extension's webhook queue entity (civicrm_paymentprocessor_webhook).
 *
 * A stub for PHPStan only (see phpstan.neon.dist): the extension requires
 * mjwshared, which is not installed where static analysis runs.
 */
class PaymentprocessorWebhook extends DAOEntity {

}

namespace Civi\Firewall;

/**
 * The Firewall extension's main class.
 *
 * A stub for PHPStan only: CRM_Square_Firewall uses the Firewall extension
 * when it is installed, which it is not where static analysis runs.
 */
class Firewall {

  /**
   * The client's IP address.
   *
   * @return string
   */
  public function getIPAddress() {
    return '';
  }

}

namespace Civi\Firewall\Event;

/**
 * The Firewall extension's declined-card event. A stub for PHPStan only.
 */
class DeclinedCardEvent {

  /**
   * Record a declined card from an IP address.
   *
   * @param string $ipAddress
   * @param string $source
   */
  public static function trigger($ipAddress, $source) {
  }

}
