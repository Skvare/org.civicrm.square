<?php

use CRM_Square_ExtensionUtil as E;

/**
 * Administer > System Settings > Square Reconciliation.
 *
 * Looks up what Square recorded for one processor (a subscription and its
 * installments, a payment or refund, payments or refunds in a date range,
 * or recent events), shows for each whether CiviCRM recorded it and what the
 * webhook queue holds for it, and processes the selected ones, or retries
 * their webhook queue records (see CRM_Square_Reconciliation).
 *
 * The search is in the URL, so the results can be reloaded and shared. Each
 * action re-runs the search, acts on the selected rows of the fresh results
 * (only their keys are taken from the browser), then redirects back to the
 * search, which shows the outcome.
 */
class CRM_Square_Form_Reconcile extends CRM_Core_Form {

  /**
   * The search's parameters.
   */
  protected const PARAMS = ['pp', 'lookup', 'ref', 'sid', 'type', 'from', 'to'];

  /**
   * Rows found by the search.
   *
   * @var array[]
   */
  protected array $rows = [];

  /**
   * Build the form, and run the search.
   */
  public function buildQuickForm() {
    CRM_Utils_System::setTitle(E::ts('Square Reconciliation'));

    $processors = [];
    foreach (CRM_Square_Processors::getActive() as $id => $processor) {
      $processors[$id] = CRM_Square_Processors::label($processor);
    }
    $this->add('select', 'pp', E::ts('Payment processor'), $processors, TRUE);
    $this->addRadio('lookup', E::ts('Look up'), [
      'subscription' => E::ts('A subscription and its installments'),
      'id' => E::ts('A Square payment or refund'),
      'range' => E::ts('Payments or refunds in a date range'),
      'events' => E::ts('Square events in a date range (last 28 days)'),
    ], [], '<br>', TRUE);
    $this->add('text', 'ref', E::ts('Square subscription ID or recurring contribution ID'), ['size' => 40]);
    $this->add('text', 'sid', E::ts('Square payment or refund ID'), ['size' => 40]);
    $this->add('select', 'type', E::ts('Type'), ['payments' => E::ts('Payments'), 'refunds' => E::ts('Refunds')]);
    $this->add('datepicker', 'from', E::ts('From'), [], FALSE, ['time' => FALSE]);
    $this->add('datepicker', 'to', E::ts('To'), [], FALSE, ['time' => FALSE]);
    $this->addFormRule([self::class, 'formRule']);

    $this->addButtons([
      ['type' => 'submit', 'name' => E::ts('Search'), 'subName' => 'search', 'isDefault' => TRUE],
      ['type' => 'submit', 'name' => E::ts('Process selected'), 'subName' => 'process', 'icon' => 'fa-check'],
      [
        'type' => 'submit',
        'name' => E::ts('Retry selected webhook queue records'),
        'subName' => 'retry',
        'icon' => 'fa-refresh',
      ],
    ]);
    $this->assign('searchButton', $this->getButtonName('submit', 'search'));
    $this->assign('processButton', $this->getButtonName('submit', 'process'));
    $this->assign('retryButton', $this->getButtonName('submit', 'retry'));
    $this->assign('queueUrl', CRM_Utils_System::url('civicrm/paymentprocessorwebhooks', 'reset=1'));

    $this->assign('rows', []);
    $this->assign('searched', FALSE);
    $this->assign('searchError', NULL);
    $this->assign('truncated', FALSE);
    $this->assign('eventSearchDisabled', FALSE);
    $this->assign('maxRows', CRM_Square_Reconciliation::MAX_ROWS);

    // A search button only redirects to the search's URL (see postProcess()).
    $isSearchButton = $this->isSubmitted() && $this->getSubmitValue($this->getButtonName('submit', 'search')) !== NULL;
    $params = $this->getSearchParams();
    if (!$isSearchButton && self::isComplete($params, array_keys($processors))) {
      $this->search($params);
    }
    parent::buildQuickForm();
  }

  /**
   * Set the form's default values: the search in the URL.
   */
  public function setDefaultValues() {
    $params = $this->getUrlParams();
    $processorIds = array_keys(CRM_Square_Processors::getActive());
    return [
      'pp' => $params['pp'] !== '' ? $params['pp'] : (string) reset($processorIds),
      'lookup' => $params['lookup'] !== '' ? $params['lookup'] : 'subscription',
      'ref' => $params['ref'],
      'sid' => $params['sid'],
      'type' => $params['type'] !== '' ? $params['type'] : 'payments',
      'from' => $params['from'] !== '' ? $params['from'] : date('Y-m-d', strtotime('-7 days')),
      'to' => $params['to'] !== '' ? $params['to'] : date('Y-m-d'),
    ];
  }

  /**
   * Validate the search.
   *
   * @param array $values
   *
   * @return array|true
   */
  public static function formRule($values) {
    $errors = [];
    $lookup = $values['lookup'] ?? '';
    if ($lookup === 'subscription' && trim((string) ($values['ref'] ?? '')) === '') {
      $errors['ref'] = E::ts('Enter a Square subscription ID or a recurring contribution ID.');
    }
    if ($lookup === 'id' && trim((string) ($values['sid'] ?? '')) === '') {
      $errors['sid'] = E::ts('Enter a Square payment or refund ID.');
    }
    if (in_array($lookup, ['range', 'events'], TRUE)) {
      $from = strtotime((string) ($values['from'] ?? ''));
      $to = strtotime((string) ($values['to'] ?? ''));
      if (!$from) {
        $errors['from'] = E::ts('Enter the first day.');
      }
      if (!$to) {
        $errors['to'] = E::ts('Enter the last day.');
      }
      if ($from && $to && $from > $to) {
        $errors['to'] = E::ts('The last day must not be before the first.');
      }
    }
    return $errors ?: TRUE;
  }

  /**
   * Search, process or retry; then show the search again.
   */
  public function postProcess() {
    $button = (string) $this->controller->getButtonName();
    if (str_ends_with($button, '_process')) {
      $this->processSelected();
    }
    elseif (str_ends_with($button, '_retry')) {
      $this->retrySelected();
    }
    $params = $this->getSearchParams();
    CRM_Utils_System::redirect(CRM_Utils_System::url('civicrm/admin/square/reconcile', http_build_query(['reset' => 1] + $params), FALSE, NULL, FALSE));
  }

  /**
   * Process the selected rows.
   */
  protected function processSelected(): void {
    $rows = $this->getSelectedRows();
    if (!$rows) {
      CRM_Core_Session::setStatus(E::ts('Select the records to process.'), E::ts('Nothing processed'), 'info');
      return;
    }
    $outcomes = $this->getReconciliation()->process($rows);
    $this->reportOutcomes($outcomes, E::ts('Processed'));
  }

  /**
   * Process the webhook queue records of the selected rows again.
   */
  protected function retrySelected(): void {
    $outcomes = [];
    $reconciliation = $this->getReconciliation();
    foreach ($this->getSelectedRows() as $row) {
      $status = $row['queue']['status'] ?? '';
      if (in_array($status, ['error', 'new'], TRUE)) {
        $outcomes[$row['key']] = $reconciliation->retryQueueRecord((int) $row['queue']['id']);
      }
    }
    if (!$outcomes) {
      CRM_Core_Session::setStatus(E::ts('Select records whose webhook queue record failed or is waiting.'), E::ts('Nothing retried'), 'info');
      return;
    }
    $this->reportOutcomes($outcomes, E::ts('Retried'));
  }

  /**
   * Tell the user what processing did.
   *
   * @param array $outcomes
   *   From CRM_Square_Reconciliation::process(), by row key.
   * @param string $title
   */
  protected function reportOutcomes(array $outcomes, string $title): void {
    $labels = [
      'success' => E::ts('recorded'),
      'new' => E::ts('waiting: the scheduled job will retry it'),
      'error' => E::ts('failed'),
      'duplicate' => E::ts('already received'),
      'skipped' => E::ts('skipped'),
    ];
    $lines = [];
    $hasError = FALSE;
    foreach ($outcomes as $key => $outcome) {
      $status = $outcome['status'] ?? 'error';
      $hasError = $hasError || $status === 'error';
      $line = htmlspecialchars($key) . ': ' . htmlspecialchars($labels[$status] ?? $status);
      if ($status !== 'success' && !empty($outcome['message'])) {
        $line .= ' — ' . htmlspecialchars(mb_substr($outcome['message'], 0, 250));
      }
      $lines[] = $line;
    }
    CRM_Core_Session::setStatus(implode('<br>', $lines), $title, $hasError ? 'error' : 'success');
  }

  /**
   * The rows of the fresh search whose keys the user selected.
   *
   * @return array[]
   */
  protected function getSelectedRows(): array {
    $selected = $this->getSubmitValue('square_select');
    $selected = array_flip(array_map('strval', is_array($selected) ? $selected : []));
    return array_values(array_filter($this->rows, fn (array $row) => isset($selected[$row['key']])));
  }

  /**
   * Run the search, and show its results.
   *
   * @param array $params
   */
  protected function search(array $params): void {
    $this->assign('searched', TRUE);
    try {
      $reconciliation = $this->getReconciliation($params);
      switch ($params['lookup']) {
        case 'subscription':
          $this->rows = $reconciliation->findBySubscription($params['ref']);
          break;

        case 'id':
          $this->rows = $reconciliation->findBySquareId($params['sid']);
          break;

        case 'range':
          $this->rows = $reconciliation->findInRange($params['type'] === 'refunds' ? 'refunds' : 'payments', $params['from'], $params['to']);
          break;

        case 'events':
          $this->rows = $reconciliation->findEvents($params['from'], $params['to']);
          $this->assign('eventSearchDisabled', CRM_Square_Processors::getObject((int) $params['pp'])->eventRecovery()->getSearchEnabledSince() === NULL);
          break;
      }
      $this->assign('truncated', $reconciliation->isTruncated());
    }
    catch (\Throwable $e) {
      $this->rows = [];
      $this->assign('searchError', $e->getMessage());
    }
    $this->assign('rows', array_map([$this, 'displayRow'], $this->rows));
  }

  /**
   * A row as the template shows it.
   *
   * @param array $row
   *
   * @return array
   */
  protected function displayRow(array $row): array {
    $types = [
      'payment' => E::ts('Payment'),
      'refund' => E::ts('Refund'),
      'installment' => E::ts('Installment'),
      'subscription' => E::ts('Subscription'),
      'event' => E::ts('Event'),
    ];
    $queue = $row['queue'] ?? NULL;
    $timestamp = empty($row['date']) ? FALSE : strtotime((string) $row['date']);
    return [
      'key' => $row['key'],
      'type' => $types[$row['type']] ?? $row['type'],
      'square_id' => $row['square_id'],
      'detail' => $row['detail'],
      'date' => $timestamp ? CRM_Utils_Date::customFormat(date('Y-m-d H:i:s', $timestamp)) : '',
      'amount' => $row['amount'] === NULL ? '' : CRM_Utils_Money::format($row['amount'], $row['currency']),
      'square_status' => $row['square_status'],
      'civicrm_state' => $row['civicrm']['state'] ?? '',
      'civicrm_label' => $row['civicrm']['label'] ?? '',
      'civicrm_url' => $row['civicrm']['url'] ?? NULL,
      'queue_id' => $queue['id'] ?? NULL,
      'queue_status' => $queue['status'] ?? '',
      'queue_message' => $queue['message'] ?? '',
      'queue_manual' => !empty($queue['manual']),
      'processable' => !empty($row['processable']),
      'retryable' => in_array($queue['status'] ?? '', ['error', 'new'], TRUE),
      'note' => $row['note'] ?? '',
    ];
  }

  /**
   * The reconciliation of the searched processor.
   *
   * @param array|null $params
   *
   * @return \CRM_Square_Reconciliation
   *
   * @throws \CRM_Core_Exception
   */
  protected function getReconciliation(?array $params = NULL): CRM_Square_Reconciliation {
    $params ??= $this->getSearchParams();
    $processorId = (int) $params['pp'];
    if (!isset(CRM_Square_Processors::getActive()[$processorId])) {
      throw new CRM_Core_Exception(E::ts('Choose an active Square payment processor.'));
    }
    return CRM_Square_Processors::getObject($processorId)->reconciliation();
  }

  /**
   * The search: as submitted, or else from the URL.
   *
   * @return array
   */
  protected function getSearchParams(): array {
    if (!$this->isSubmitted()) {
      return $this->getUrlParams();
    }
    $params = [];
    foreach (self::PARAMS as $name) {
      $value = $this->getSubmitValue($name);
      $params[$name] = is_scalar($value) ? trim((string) $value) : '';
    }
    return $params;
  }

  /**
   * The search in the URL.
   *
   * @return array
   */
  protected function getUrlParams(): array {
    $params = [];
    foreach (self::PARAMS as $name) {
      $params[$name] = trim((string) CRM_Utils_Request::retrieveValue($name, 'String', ''));
    }
    return $params;
  }

  /**
   * Whether a search has everything it needs.
   *
   * @param array $params
   * @param int[] $processorIds
   *   The active Square processors.
   *
   * @return bool
   */
  protected static function isComplete(array $params, array $processorIds): bool {
    if (!in_array((int) $params['pp'], array_map('intval', $processorIds), TRUE)) {
      return FALSE;
    }
    return match ($params['lookup']) {
      'subscription' => $params['ref'] !== '',
      'id' => $params['sid'] !== '',
      'range', 'events' => $params['from'] !== '' && $params['to'] !== '' && self::formRule($params) === TRUE,
      default => FALSE,
    };
  }

}
