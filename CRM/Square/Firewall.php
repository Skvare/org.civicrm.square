<?php

use Civi\Firewall\Firewall;
use Civi\Firewall\Event\DeclinedCardEvent;

/**
 * Reports card-testing signals to the Firewall extension, when it is installed.
 *
 * The Firewall extension (https://civicrm.org/extensions/firewall) blocks an
 * IP address once it has caused enough fraud or declined-card events, which
 * is what stops a card-testing run. Without it, nothing here does anything.
 * Square's own checks (Risk Manager, buyer verification when the card is
 * tokenized) still apply either way.
 */
class CRM_Square_Firewall {

  /**
   * Whether the Firewall extension is installed.
   *
   * @return bool
   */
  public static function isInstalled(): bool {
    return class_exists('\Civi\Firewall\Firewall');
  }

  /**
   * Record that Square declined a card submitted from this request's IP address.
   *
   * Many declines from one address in a short time is the signature of card
   * testing; the Firewall extension decides when to block it.
   *
   * @param string $source
   *   Where it happened, for the Firewall's log.
   */
  public static function declinedCard(string $source): void {
    if (!class_exists('\Civi\Firewall\Event\DeclinedCardEvent')) {
      return;
    }
    try {
      DeclinedCardEvent::trigger(self::ipAddress(), $source);
    }
    catch (\Throwable $e) {
      // Never let the firewall stop the payer being told about the decline.
      \Civi::log('square')->warning('Square: could not report a declined card to the Firewall extension: ' . $e->getMessage());
    }
  }

  /**
   * The client's IP address, as the Firewall extension sees it.
   *
   * @return string
   */
  protected static function ipAddress(): string {
    if (class_exists('\Civi\Firewall\Firewall')) {
      // Called on an instance: getIPAddress() is static only in later
      // Firewall releases.
      return (string) (new Firewall())->getIPAddress();
    }
    return (string) CRM_Utils_System::ipAddress();
  }

}
