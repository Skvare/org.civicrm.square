<?php

use Civi\Api4\ContributionPage;
use Civi\Api4\Event;
use Civi\Api4\Extension;
use Civi\Api4\PaymentprocessorWebhook;
use CRM_Square_ExtensionUtil as E;
use Psr\Log\LogLevel;

/**
 * System Status checks for the Square payment processors (hook_civicrm_check).
 *
 * - the mjwshared extension is recent enough;
 * - each active Square processor is fully configured, its credentials work,
 *   its Square location is active, and takes the currency of the
 *   contribution pages and events that use it;
 * - each has a working webhook subscription (on production sites), and
 *   Square event search is enabled so missed webhooks can be recovered;
 * - no Square webhook is stuck in the queue, in error or never processed;
 * - card-testing protection (Firewall extension, minimum amount).
 */
class CRM_Square_Check {

  /**
   * The oldest mjwshared release this extension has been tested with.
   */
  public const MIN_VERSION_MJWSHARED = '1.5.5';

  /**
   * How far back, in days, failed webhooks are reported.
   */
  public const WEBHOOK_ERROR_DAYS = 30;

  /**
   * How long, in hours, a webhook may wait in 'new' before it is reported.
   */
  public const WEBHOOK_STUCK_HOURS = 2;

  /**
   * The messages found so far.
   *
   * @var \CRM_Utils_Check_Message[]
   */
  protected array $messages = [];

  /**
   * Run every check.
   *
   * @return \CRM_Utils_Check_Message[]
   */
  public function run(): array {
    $this->checkMjwshared();
    $processors = CRM_Square_Processors::getActive();
    if (!$processors) {
      return $this->messages;
    }
    $isProduction = in_array((string) Civi::settings()->get('environment'), ['', 'Production'], TRUE);
    foreach ($processors as $id => $processor) {
      $this->checkProcessor((int) $id, $processor, $isProduction);
    }
    $this->checkWebhookQueue(array_keys($processors));
    $this->checkCardTesting($processors);
    return $this->messages;
  }

  /**
   * Check the mjwshared extension, which holds the webhook queue.
   */
  protected function checkMjwshared(): void {
    $extension = Extension::get(FALSE)
      ->addSelect('status', 'version')
      ->addWhere('key', '=', 'mjwshared')
      ->execute()
      ->first();
    if (empty($extension) || $extension['status'] !== 'installed') {
      $this->add('square_mjwshared', E::ts('The Square payment processor needs the Payment Shared (mjwshared) extension, which is not installed, to receive webhooks.'), E::ts('Square: Missing Requirements'), LogLevel::ERROR, 'fa-exclamation-triangle');
      return;
    }
    $version = preg_replace('/-dev$/', '', (string) $extension['version']);
    if ($version !== '' && version_compare($version, self::MIN_VERSION_MJWSHARED, '<')) {
      $this->add('square_mjwshared_version', E::ts('The Square payment processor needs version %1 or later of the Payment Shared (mjwshared) extension, but this site has version %2.', [
        1 => self::MIN_VERSION_MJWSHARED,
        2 => $extension['version'],
      ]), E::ts('Square: Missing Requirements'), LogLevel::ERROR, 'fa-exclamation-triangle', [
        E::ts('Upgrade Payment Shared'),
        'civicrm/admin/extensions',
        ['action' => 'update', 'id' => 'mjwshared', 'key' => 'mjwshared'],
      ]);
    }
  }

  /**
   * Check one processor's configuration, location, currency and webhook.
   *
   * @param int $id
   * @param array $processor
   *   A row from CRM_Square_Processors::getActive().
   * @param bool $isProduction
   */
  protected function checkProcessor(int $id, array $processor, bool $isProduction): void {
    $label = CRM_Square_Processors::label($processor);
    $title = E::ts('Square: %1', [1 => $label]);
    $editLink = [
      E::ts('Edit payment processor'),
      'civicrm/admin/paymentProcessor/edit',
      ['action' => 'update', 'id' => $id, 'reset' => 1],
    ];
    try {
      $object = CRM_Square_Processors::getObject($id);
    }
    catch (\Throwable $e) {
      $this->add("square_processor_{$id}", E::ts('The payment processor could not be loaded: %1', [1 => htmlspecialchars($e->getMessage())]), $title, LogLevel::ERROR, 'fa-credit-card', $editLink);
      return;
    }

    $configError = $object->checkConfig();
    if ($configError) {
      $this->add("square_config_{$id}", htmlspecialchars($configError), $title, LogLevel::ERROR, 'fa-credit-card', $editLink);
      return;
    }

    try {
      $location = $object->locations()->get(TRUE);
    }
    catch (\Throwable $e) {
      $this->add("square_location_{$id}", E::ts('Square refused the access token or location ID, or could not be reached, so payments cannot be taken: %1', [1 => htmlspecialchars($e->getMessage())]), $title, LogLevel::ERROR, 'fa-credit-card', $editLink);
      return;
    }
    if ($location['status'] !== 'ACTIVE') {
      $this->add("square_location_{$id}", E::ts('The Square location "%1" is not active, so Square refuses payments taken at it.', [1 => htmlspecialchars($location['name'])]), $title, LogLevel::ERROR, 'fa-credit-card', $editLink);
    }
    $this->checkCurrency($id, $title, $location);

    if ($isProduction) {
      $this->checkWebhook($id, $object, $title);
    }
  }

  /**
   * Check the contribution pages and events using a processor take its location's currency.
   *
   * @param int $id
   * @param string $title
   * @param array $location
   *   From CRM_Square_Locations::get().
   */
  protected function checkCurrency(int $id, string $title, array $location): void {
    $currency = $location['currency'];
    if ($currency === '') {
      return;
    }
    $defaultCurrency = strtoupper((string) Civi::settings()->get('defaultCurrency'));
    $mismatches = [];
    $queries = [];
    try {
      $queries[] = ContributionPage::get(FALSE)
        ->addWhere('is_active', '=', TRUE);
    }
    catch (\Throwable $e) {
      // CiviContribute disabled.
    }
    try {
      $queries[] = Event::get(FALSE)
        ->addWhere('is_active', '=', TRUE)
        ->addWhere('is_template', '=', FALSE)
        ->addClause('OR', ['start_date', '>=', date('Y-m-d')], ['end_date', '>=', date('Y-m-d')]);
    }
    catch (\Throwable $e) {
      // CiviEvent disabled.
    }
    foreach ($queries as $query) {
      try {
        $rows = $query
          ->addSelect('id', 'title', 'currency')
          ->addWhere('payment_processor', 'CONTAINS', $id)
          ->execute();
      }
      catch (\Throwable $e) {
        continue;
      }
      foreach ($rows as $row) {
        $pageCurrency = strtoupper((string) (($row['currency'] ?? '') ?: $defaultCurrency));
        if ($pageCurrency !== '' && $pageCurrency !== $currency) {
          $mismatches[] = htmlspecialchars("{$row['title']} ({$pageCurrency})");
        }
      }
    }
    if ($mismatches) {
      $this->add("square_currency_{$id}", E::ts('The Square location "%1" takes payments in %2 only, so card payments fail on: %3. Change their currency, or use a Square location that takes it.', [
        1 => htmlspecialchars($location['name']),
        2 => $currency,
        3 => implode(', ', $mismatches),
      ]), $title, LogLevel::WARNING, 'fa-money');
    }
  }

  /**
   * Check a processor's webhook subscription, and that missed webhooks can be recovered.
   *
   * @param int $id
   * @param \CRM_Core_Payment_Square $object
   * @param string $title
   */
  protected function checkWebhook(int $id, CRM_Core_Payment_Square $object, string $title): void {
    $fixLink = [E::ts('View and fix'), 'civicrm/admin/square/webhook', ['reset' => 1]];
    try {
      $problems = $object->webhooks()->getProblems();
    }
    catch (\Throwable $e) {
      $this->add("square_webhook_{$id}", E::ts('Could not check the Square webhook subscription: %1', [1 => htmlspecialchars($e->getMessage())]), $title, LogLevel::WARNING, 'fa-plug', $fixLink);
      return;
    }
    if ($problems) {
      $this->add("square_webhook_{$id}", implode('<br>', array_map('htmlspecialchars', $problems)), $title, LogLevel::ERROR, 'fa-plug', $fixLink);
    }
    if ($object->eventRecovery()->getSearchEnabledSince() === NULL) {
      $this->add("square_event_search_{$id}", E::ts('Square event search is not enabled, so webhooks that never reach CiviCRM (for example while the site is down) cannot be recovered.'), $title, LogLevel::NOTICE, 'fa-plug', $fixLink);
    }
  }

  /**
   * Check for Square webhooks that failed, or have waited too long.
   *
   * @param int[] $processorIds
   */
  protected function checkWebhookQueue(array $processorIds): void {
    $queueLink = [E::ts('View webhook queue'), 'civicrm/paymentprocessorwebhooks', []];
    $errorCount = PaymentprocessorWebhook::get(FALSE)
      ->selectRowCount()
      ->addWhere('payment_processor_id', 'IN', $processorIds)
      ->addWhere('status', '=', 'error')
      ->addWhere('created_date', '>', date('Y-m-d H:i:s', strtotime('-' . self::WEBHOOK_ERROR_DAYS . ' days')))
      ->execute()
      ->countMatched();
    if ($errorCount) {
      $this->add('square_webhook_errors', E::ts('%1 Square webhook(s) from the last %2 days could not be processed, so CiviCRM may be missing payments, installments or refunds that Square recorded. Each record\'s message says why. Once the cause is fixed, use the queue\'s Retry action.', [
        1 => $errorCount,
        2 => self::WEBHOOK_ERROR_DAYS,
      ]), E::ts('Square: Webhooks Failed'), LogLevel::WARNING, 'fa-plug', $queueLink);
    }

    $stuckCount = PaymentprocessorWebhook::get(FALSE)
      ->selectRowCount()
      ->addWhere('payment_processor_id', 'IN', $processorIds)
      ->addWhere('status', '=', 'new')
      ->addWhere('created_date', '<', date('Y-m-d H:i:s', strtotime('-' . self::WEBHOOK_STUCK_HOURS . ' hours')))
      ->execute()
      ->countMatched();
    if ($stuckCount) {
      $this->add('square_webhook_stuck', E::ts('%1 Square webhook(s) have been waiting more than %2 hours to be retried. Check that cron runs, and that the "Process PaymentProcessor Webhooks" scheduled job is enabled.', [
        1 => $stuckCount,
        2 => self::WEBHOOK_STUCK_HOURS,
      ]), E::ts('Square: Webhooks Waiting'), LogLevel::WARNING, 'fa-clock-o', [
        E::ts('Scheduled jobs'),
        'civicrm/admin/job',
        ['reset' => 1],
      ]);
    }
  }

  /**
   * Recommend card-testing protection for live processors.
   *
   * @param array[] $processors
   */
  protected function checkCardTesting(array $processors): void {
    $hasLive = (bool) array_filter($processors, fn (array $processor) => empty($processor['is_test']));
    if (!$hasLive || CRM_Square_Firewall::isInstalled()) {
      return;
    }
    $message = E::ts('Public payment forms are a target for card testing: fraudsters trying stolen card numbers with many small payments. The Firewall extension blocks the IP addresses they come from once Square declines enough of their cards.');
    if ((float) Civi::settings()->get('square_min_amount') <= 0) {
      $message .= ' ' . E::ts('Setting a minimum card payment amount in Square Settings also helps.');
    }
    $this->add('square_card_testing', $message, E::ts('Square: Card Testing Protection'), LogLevel::NOTICE, 'fa-shield', [
      E::ts('Firewall extension'),
      'civicrm/admin/extensions',
      ['action' => 'update', 'id' => 'firewall', 'key' => 'firewall'],
    ]);
  }

  /**
   * Add a message.
   *
   * @param string $name
   * @param string $message
   *   HTML.
   * @param string $title
   * @param string $level
   *   A \Psr\Log\LogLevel.
   * @param string $icon
   * @param array|null $action
   *   A link to fix it, as [title, path, query].
   */
  protected function add(string $name, string $message, string $title, string $level, string $icon, ?array $action = NULL): void {
    $checkMessage = new CRM_Utils_Check_Message($name, $message, $title, $level, $icon);
    if ($action) {
      [$actionTitle, $path, $query] = $action;
      $checkMessage->addAction($actionTitle, FALSE, 'href', ['path' => $path, 'query' => $query]);
    }
    $this->messages[] = $checkMessage;
  }

}
