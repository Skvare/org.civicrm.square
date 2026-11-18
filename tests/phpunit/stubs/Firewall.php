<?php

/**
 * @file
 * Stand-ins for the Firewall extension's classes, recording what is reported.
 */

namespace Civi\Firewall;

/**
 * Stand-in for the Firewall extension's main class.
 */
class Firewall {

  /**
   * @return string
   */
  public static function getIPAddress() {
    return '192.0.2.10';
  }

}

namespace Civi\Firewall\Event;

/**
 * Stand-in for the Firewall extension's declined-card event.
 */
class DeclinedCardEvent {

  /**
   * Each trigger() call, as [ipAddress, source].
   *
   * @var array
   */
  public static array $triggered = [];

  /**
   * @param string $ipAddress
   * @param string $source
   */
  public static function trigger($ipAddress, $source) {
    self::$triggered[] = [$ipAddress, $source];
  }

}
