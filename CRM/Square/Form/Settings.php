<?php

use CRM_Square_ExtensionUtil as E;

/**
 * Class CRM_Square_Form_Settings.
 *
 * Administer > System Settings > Square Settings.
 *
 * Lets an admin toggle verbose Square webhook debug logging (see
 * CRM_Core_Payment_SquareDebugLogger), and the import of Square payments
 * made outside CiviCRM (see CRM_Square_Reconciler), without needing
 * shell/API access.
 */
class CRM_Square_Form_Settings extends CRM_Core_Form {

  /**
   * The settings on this form, with their labels.
   */
  protected function getSquareSettings(): array {
    return [
      'square_ipn_debug_logging' => E::ts('Enable Square IPN Debug Logging'),
      'square_import_external_payments' => E::ts('Import Square payments made outside CiviCRM'),
    ];
  }

  /**
   * Build the settings form.
   */
  public function buildQuickForm() {
    CRM_Utils_System::setTitle(E::ts('Square Settings'));
    foreach ($this->getSquareSettings() as $name => $label) {
      $this->addYesNo($name, $label);
    }
    $this->addButtons([
      [
        'type' => 'submit',
        'name' => E::ts('Save'),
        'isDefault' => TRUE,
      ],
    ]);
    parent::buildQuickForm();
  }

  /**
   * Set the form's default values from the current setting values.
   */
  public function setDefaultValues() {
    $defaults = parent::setDefaultValues();
    foreach (array_keys($this->getSquareSettings()) as $name) {
      $defaults[$name] = (int) (bool) Civi::settings()->get($name);
    }
    return $defaults;
  }

  /**
   * Save the submitted setting values.
   */
  public function postProcess() {
    $values = $this->exportValues();
    foreach (array_keys($this->getSquareSettings()) as $name) {
      Civi::settings()->set($name, !empty($values[$name]));
    }
    CRM_Core_Session::setStatus(E::ts('Square settings saved.'), E::ts('Saved'), 'success');
    parent::postProcess();
  }

}
