<?php

/**
 * @file
 * Allow autoloading of extension classes.
 */

$extensionRoot = dirname(__DIR__, 2);

require_once $extensionRoot . '/vendor/autoload.php';

// Load real CiviCRM first, when a bootstrap file is given, so that its
// classes take precedence: every stand-in below is only defined if the real
// class is missing. Focused unit tests run without it.
$cmsPath = getenv('CIVICRM_BOOTSTRAP_FILE');
if ($cmsPath && file_exists($cmsPath)) {
  require_once $cmsPath;
}

if (!class_exists('CRM_Core_Payment')) {

  /**
   * Minimal stand-in for CiviCRM core's payment-processor base class.
   */
  abstract class CRM_Core_Payment {

    /**
     * Matches the real CRM_Core_Payment base class.
     *
     * See CRM/Core/Payment.php, so tests don't trip a "dynamic property"
     * deprecation that wouldn't occur against the real CiviCRM core class.
     *
     * @var string
     */
    // phpcs:ignore Drupal.NamingConventions.ValidVariableName, PSR2.Classes.PropertyDeclaration.Underscore
    protected $_component;

  }
}
if (!class_exists('CRM_Core_Exception')) {

  /**
   * Minimal stand-in for CiviCRM core's exception class.
   */
  class CRM_Core_Exception extends Exception {
  }
}
if (!class_exists('CRM_Square_ExtensionUtil')) {

  /**
   * Minimal stand-in for civix's generated extension-path helper.
   */
  class CRM_Square_ExtensionUtil {

    /**
     * @return string
     */
    public static function path(): string {
      return dirname(__DIR__, 2);
    }

    /**
     * @param string $path
     *
     * @return string
     */
    public static function url(string $path = ''): string {
      return $path;
    }

    /**
     * @param string $text
     * @param array $params
     *
     * @return string
     */
    public static function ts($text, array $params = []): string {
      return ts($text, $params);
    }

  }
}
if (!function_exists('ts')) {

  /**
   * Minimal stand-in for CiviCRM's translation function.
   *
   * @param string $message
   * @param array $params
   *
   * @return string
   */
  function ts($message, array $params = []) {
    foreach ($params as $key => $value) {
      $message = str_replace("%{$key}", $value, $message);
    }
    return $message;
  }

}
if (!class_exists('CRM_Contribute_PseudoConstant')) {

  /**
   * Minimal stand-in for CiviCRM core's contribution-status lookup.
   *
   * Used by CRM_Core_Payment_Square::contributionStatusId(). Values match
   * CiviCRM's own default 'contribution_status' option group so tests
   * exercise the same IDs the production code assumes elsewhere.
   */
  class CRM_Contribute_PseudoConstant {

    /**
     * @return array
     */
    public static function contributionStatus(): array {
      return [
        1 => 'Completed',
        2 => 'Pending',
        3 => 'Cancelled',
        4 => 'Failed',
        5 => 'In Progress',
        6 => 'Overdue',
        7 => 'Refunded',
      ];
    }

  }
}
if (!class_exists('CRM_Core_PseudoConstant')) {

  /**
   * Minimal stand-in for CiviCRM core's pseudoconstant lookup.
   *
   * Used by CRM_Core_Payment_Square::pseudoConstantId() for
   * contribution_status_id, payment_instrument_id, and financial_type_id.
   * Values match CiviCRM's own default option groups, seeded on every
   * fresh install.
   */
  class CRM_Core_PseudoConstant {

    /**
     * @param string $baoName
     * @param string $fieldName
     * @param string $value
     *
     * @return int|false
     */
    public static function getKey($baoName, $fieldName, $value) {
      $options = [
        'contribution_status_id' => [
          1 => 'Completed',
          2 => 'Pending',
          3 => 'Cancelled',
          4 => 'Failed',
          5 => 'In Progress',
          6 => 'Overdue',
          7 => 'Refunded',
        ],
        'payment_instrument_id' => [
          1 => 'Credit Card',
          2 => 'Debit Card',
          3 => 'Cash',
          4 => 'Check',
          5 => 'EFT',
        ],
        'financial_type_id' => [
          1 => 'Donation',
          2 => 'Member Dues',
          3 => 'Campaign Contribution',
          4 => 'Event Fee',
        ],
      ];
      return array_search($value, $options[$fieldName] ?? [], TRUE);
    }

  }
}
if (!class_exists('Civi')) {

  /**
   * Minimal stand-in for CiviCRM's Civi service locator.
   *
   * Only settings() and log() are provided. Log calls are recorded in
   * Civi::$logged so tests can assert reconciliation errors were raised.
   */
  class Civi {

    /**
     * Messages logged during the current test, as [level, message] pairs.
     *
     * @var array
     */
    public static array $logged = [];

    /**
     * @return object
     */
    public static function settings() {
      return new class() {

        /**
         * @param string $name
         *
         * @return mixed
         */
        public function get($name) {
          return NULL;
        }

      };
    }

    /**
     * @param string $channel
     *
     * @return object
     */
    public static function log($channel = 'default') {
      return new class() {

        /**
         * @param string $level
         * @param array $args
         */
        public function __call($level, $args) {
          Civi::$logged[] = [$level, (string) ($args[0] ?? '')];
        }

      };
    }

  }
}
