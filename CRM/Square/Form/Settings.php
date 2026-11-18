<?php

use CRM_Square_ExtensionUtil as E;

/**
 * Class CRM_Square_Form_Settings.
 *
 * Administer > System Settings > Square Settings.
 *
 * Lets an admin toggle verbose Square webhook debug logging (see
 * CRM_Core_Payment_SquareDebugLogger) and the import of Square payments
 * made outside CiviCRM (see CRM_Square_Reconciler), and set the minimum
 * card payment amount and the card statement description, without needing
 * shell/API access.
 */
class CRM_Square_Form_Settings extends CRM_Core_Form {

  /**
   * The Yes/No settings on this form, with their labels.
   */
  protected function getSquareSettings(): array {
    return [
      'square_ipn_debug_logging' => E::ts('Enable Square IPN Debug Logging'),
      'square_import_external_payments' => E::ts('Import Square payments made outside CiviCRM'),
    ];
  }

  /**
   * The text settings on this form, with their labels.
   */
  protected function getSquareTextSettings(): array {
    return [
      'square_min_amount' => E::ts('Minimum card payment amount'),
      'square_statement_description' => E::ts('Card statement description'),
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
    $this->add('text', 'square_min_amount', $this->getSquareTextSettings()['square_min_amount'], [
      'size' => 10,
      'maxlength' => 10,
    ]);
    $this->addRule('square_min_amount', E::ts('Enter an amount, such as 5 or 5.00.'), 'money');
    $this->add('text', 'square_statement_description', $this->getSquareTextSettings()['square_statement_description'], [
      'size' => CRM_Core_Payment_Square::STATEMENT_DESCRIPTION_MAX_LENGTH,
      'maxlength' => CRM_Core_Payment_Square::STATEMENT_DESCRIPTION_MAX_LENGTH,
    ]);
    $this->addFormRule([self::class, 'formRule']);
    $this->assign('webhookUrl', CRM_Utils_System::url('civicrm/admin/square/webhook', 'reset=1'));
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
   * Validate the text settings.
   *
   * @param array $values
   *
   * @return array|true
   */
  public static function formRule($values) {
    $errors = [];
    $minAmount = trim((string) ($values['square_min_amount'] ?? ''));
    if ($minAmount !== '' && (!is_numeric(CRM_Utils_Rule::cleanMoney($minAmount)) || (float) CRM_Utils_Rule::cleanMoney($minAmount) < 0)) {
      $errors['square_min_amount'] = E::ts('Enter an amount of 0 or more.');
    }
    if (mb_strlen(trim((string) ($values['square_statement_description'] ?? ''))) > CRM_Core_Payment_Square::STATEMENT_DESCRIPTION_MAX_LENGTH) {
      $errors['square_statement_description'] = E::ts('Square accepts at most %1 characters.', [1 => CRM_Core_Payment_Square::STATEMENT_DESCRIPTION_MAX_LENGTH]);
    }
    return $errors ?: TRUE;
  }

  /**
   * Set the form's default values from the current setting values.
   */
  public function setDefaultValues() {
    $defaults = parent::setDefaultValues();
    foreach (array_keys($this->getSquareSettings()) as $name) {
      $defaults[$name] = (int) (bool) Civi::settings()->get($name);
    }
    $minAmount = (float) Civi::settings()->get('square_min_amount');
    $defaults['square_min_amount'] = $minAmount > 0 ? CRM_Utils_Money::formatLocaleNumericRoundedForDefaultCurrency($minAmount) : '';
    $defaults['square_statement_description'] = (string) Civi::settings()->get('square_statement_description');
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
    $minAmount = trim((string) ($values['square_min_amount'] ?? ''));
    Civi::settings()->set('square_min_amount', $minAmount === '' ? 0 : (float) CRM_Utils_Rule::cleanMoney($minAmount));
    Civi::settings()->set('square_statement_description', trim((string) ($values['square_statement_description'] ?? '')));
    CRM_Core_Session::setStatus(E::ts('Square settings saved.'), E::ts('Saved'), 'success');
    parent::postProcess();
  }

}
