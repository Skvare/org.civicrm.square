<?php

use CRM_Square_ExtensionUtil as E;

/**
 * Administer > System Settings > Square Webhooks.
 *
 * Shows, for each active Square payment processor, whether its Square
 * webhook subscription is set up correctly, and creates or repairs it (see
 * CRM_Square_Webhooks::fix()). Also enables Square event search, which the
 * "Square: recover missed webhook events" scheduled job needs.
 */
class CRM_Square_Form_Webhooks extends CRM_Core_Form {

  /**
   * Build the form.
   */
  public function buildQuickForm() {
    CRM_Utils_System::setTitle(E::ts('Square Webhooks'));

    $rows = [];
    foreach (CRM_Square_Processors::getActive() as $id => $processor) {
      $row = [
        'id' => $id,
        'label' => CRM_Square_Processors::label($processor),
        'url' => '',
        'problems' => [],
        'error' => NULL,
        'search_enabled_since' => NULL,
      ];
      try {
        $object = CRM_Square_Processors::getObject((int) $id);
        $webhooks = $object->webhooks();
        $row['url'] = $webhooks->getNotificationUrl();
        $row['search_enabled_since'] = $object->eventRecovery()->getSearchEnabledSince();
        $row['problems'] = $webhooks->getProblems();
      }
      catch (\Throwable $e) {
        $row['error'] = $e->getMessage();
      }
      $rows[] = $row;
    }
    $this->assign('rows', $rows);
    $this->assign('eventTypes', implode(', ', CRM_Square_Webhooks::requiredEventTypes()));
    $this->assign('environment', (string) Civi::settings()->get('environment'));

    if ($rows) {
      $this->add('checkbox', 'replace_signature_key', E::ts('Also generate new signature keys'));
      $this->add('checkbox', 'enable_event_search', E::ts('Enable Square event search'));
      $this->addButtons([
        [
          'type' => 'submit',
          'name' => E::ts('Create / update webhooks'),
          'isDefault' => TRUE,
        ],
      ]);
    }
    parent::buildQuickForm();
  }

  /**
   * Set the form's default values.
   */
  public function setDefaultValues() {
    return ['enable_event_search' => 1, 'replace_signature_key' => 0];
  }

  /**
   * Create or repair each processor's webhook subscription.
   */
  public function postProcess() {
    $values = $this->exportValues();
    $replaceKey = !empty($values['replace_signature_key']);
    foreach (CRM_Square_Processors::getActive() as $id => $processor) {
      $label = CRM_Square_Processors::label($processor);
      try {
        $object = CRM_Square_Processors::getObject((int) $id);
        $done = $object->webhooks()->fix($replaceKey);
        if (!empty($values['enable_event_search'])) {
          $object->eventRecovery()->enableSearch();
          $done[] = E::ts('Square event search is enabled, so missed webhooks can be recovered.');
        }
        CRM_Core_Session::setStatus(implode('<br>', array_map('htmlspecialchars', $done ?: [E::ts('Nothing needed changing.')])), $label, 'success');
      }
      catch (\Throwable $e) {
        \Civi::log('square')->error("Square: could not set up the webhook of payment processor {$id}: " . $e->getMessage());
        CRM_Core_Session::setStatus(htmlspecialchars($e->getMessage()), E::ts('%1: webhook not updated', [1 => $label]), 'error');
      }
    }
    // Reload, so the page shows the webhooks as they are now.
    CRM_Utils_System::redirect(CRM_Utils_System::url('civicrm/admin/square/webhook', 'reset=1', FALSE, NULL, FALSE));
  }

}
