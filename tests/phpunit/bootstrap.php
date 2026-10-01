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

// The extension's own CRM_* classes (CiviCRM's classloader loads these in
// production, from info.xml's <classloader>).
spl_autoload_register(function (string $class) use ($extensionRoot): void {
  if (str_starts_with($class, 'CRM_')) {
    $file = $extensionRoot . '/' . str_replace('_', '/', $class) . '.php';
    if (is_file($file)) {
      require_once $file;
    }
  }
});

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
   *
   * With core's constructor signature, whose previous exception is the
   * fourth argument (not PHP's third).
   */
  class CRM_Core_Exception extends Exception {

    /**
     * @param string $message
     * @param int|string $error_code
     * @param array $errorData
     * @param \Throwable|null $previous
     */
    // phpcs:ignore Drupal.NamingConventions.ValidVariableName.LowerCamelName
    public function __construct($message = '', $error_code = 0, $errorData = [], $previous = NULL) {
      parent::__construct((string) $message, is_int($error_code) ? $error_code : 0, $previous);
    }

  }
}
if (!class_exists('CRM_Extension_Upgrader_Base')) {

  /**
   * Minimal stand-in for CiviCRM core's extension upgrader base class.
   */
  abstract class CRM_Extension_Upgrader_Base {
  }
}
if (!class_exists('Civi\\Payment\\Exception\\PaymentProcessorException')) {
  require_once __DIR__ . '/stubs/PaymentProcessorException.php';
}
// The extension's hook implementations (and, through square.civix.php,
// CRM_Square_ExtensionUtil), as CiviCRM loads them.
require_once $extensionRoot . '/square.php';
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
    // Options such as 'domain' are not placeholders.
    unset($params['domain'], $params['escape'], $params['plural'], $params['count']);
    foreach ($params as $key => $value) {
      $message = str_replace("%{$key}", (string) $value, $message);
    }
    return $message;
  }

}
if (!class_exists('CRM_Core_PseudoConstant')) {

  /**
   * Minimal stand-in for CiviCRM core's pseudoconstant lookup.
   *
   * Used by CRM_Square_Status::pseudoConstantId() for contribution and
   * recurring contribution statuses, payment instruments, and financial
   * types. Values match the option groups CiviCRM 6.16 seeds on a fresh
   * install — including that contribution_status has no In Progress (5) or
   * Overdue (6), which only contribution_recur_status has.
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
        'CRM_Contribute_BAO_Contribution' => [
          'contribution_status_id' => [
            1 => 'Completed',
            2 => 'Pending',
            3 => 'Cancelled',
            4 => 'Failed',
            7 => 'Refunded',
            8 => 'Partially paid',
            9 => 'Pending refund',
            10 => 'Chargeback',
            11 => 'Template',
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
        ],
        'CRM_Contribute_BAO_ContributionRecur' => [
          'contribution_status_id' => [
            1 => 'Completed',
            2 => 'Pending',
            3 => 'Cancelled',
            4 => 'Failed',
            5 => 'In Progress',
            6 => 'Overdue',
            7 => 'Processing',
            8 => 'Failing',
          ],
        ],
      ];
      return array_search($value, $options[$baoName][$fieldName] ?? [], TRUE);
    }

    /**
     * @param int|false $id
     *
     * @return array|string|null
     */
    public static function countryIsoCode($id = FALSE) {
      $codes = [1228 => 'US', 1039 => 'CA'];
      return $id ? ($codes[$id] ?? NULL) : $codes;
    }

    /**
     * @param int|false $id
     *
     * @return array|string|null
     */
    public static function stateProvinceAbbreviation($id = FALSE) {
      $abbreviations = [1042 => 'TN'];
      return $id ? ($abbreviations[$id] ?? NULL) : $abbreviations;
    }

  }
}
if (!class_exists('CRM_Core_Region')) {

  /**
   * Minimal stand-in for CiviCRM core's page regions.
   *
   * Snippets added are kept in CRM_Core_Region::$added, by region name.
   */
  class CRM_Core_Region {

    /**
     * Snippets added to each region.
     *
     * @var array
     */
    public static array $added = [];

    /**
     * @var string
     */
    private string $name;

    /**
     * @param string $name
     */
    private function __construct(string $name) {
      $this->name = $name;
    }

    /**
     * @param string $name
     *
     * @return self
     */
    public static function instance($name): self {
      return new self($name);
    }

    /**
     * @param array $snippet
     */
    public function add(array $snippet): void {
      self::$added[$this->name][] = $snippet;
    }

  }
}
if (!class_exists('CRM_Core_Resources')) {

  /**
   * Minimal stand-in for CiviCRM core's resource manager.
   *
   * Settings added are kept in CRM_Core_Resources::$settings.
   */
  class CRM_Core_Resources {

    /**
     * Settings added, merged.
     *
     * @var array
     */
    public static array $settings = [];

    /**
     * @return self
     */
    public static function singleton(): self {
      return new self();
    }

    /**
     * @param string $key
     * @param string|null $file
     *
     * @return string
     */
    public function getUrl($key, $file = NULL): string {
      return "https://example.invalid/ext/{$key}/" . ($file ?? '');
    }

    /**
     * @param array $settings
     */
    public function addSetting(array $settings): self {
      self::$settings = array_replace_recursive(self::$settings, $settings);
      return $this;
    }

  }
}
if (!class_exists('CRM_Utils_Rule')) {

  /**
   * Minimal stand-in for CiviCRM core's validation and cleaning rules.
   */
  class CRM_Utils_Rule {

    /**
     * As core's, for the default separators: drop spaces and thousands commas.
     *
     * @param string|null $value
     *
     * @return string
     */
    public static function cleanMoney($value): string {
      return str_replace([' ', "\t", "\n", ','], '', (string) ($value ?? ''));
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
     * Settings saved during the current test, by name.
     *
     * @var array
     */
    public static array $settings = [];

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
          return Civi::$settings[$name] ?? NULL;
        }

        /**
         * @param string $name
         * @param mixed $value
         */
        public function set($name, $value): void {
          Civi::$settings[$name] = $value;
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
