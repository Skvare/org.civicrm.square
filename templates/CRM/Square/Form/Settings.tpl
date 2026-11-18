{crmScope extensionKey='org.civicrm.square'}
<div class="crm-block crm-form-block crm-square-settings-form-block">
  <div class="help">
    {ts 1=$webhookUrl}To check or set up the Square webhook of each Square payment processor, go to <a href="%1">Square Webhooks</a>.{/ts}
  </div>
  <table class="form-layout">
    <tr class="crm-square-settings-form-block-debug_logging">
      <td class="label">{$form.square_ipn_debug_logging.label}</td>
      <td>
        {$form.square_ipn_debug_logging.html}
        <div class="description">{ts}When enabled, verbose Square webhook processing details (which event was received, which record was found/created/updated) are written to the CiviCRM debug log. Leave disabled in normal operation.{/ts}</div>
      </td>
    </tr>
    <tr class="crm-square-settings-form-block-import_external_payments">
      <td class="label">{$form.square_import_external_payments.label}</td>
      <td>
        {$form.square_import_external_payments.html}
        <div class="description">{ts}Square notifies CiviCRM of every payment the merchant account takes. When enabled, a completed payment that matches no CiviCRM contribution or subscription is recorded as a new Donation contribution — but only if it was taken at the payment processor's Square location and its contact can be identified (by the Square customer CiviCRM created, or by an email address only one contact has). Payments at other locations, such as point of sale, are always ignored.{/ts}</div>
      </td>
    </tr>
    <tr class="crm-square-settings-form-block-min_amount">
      <td class="label">{$form.square_min_amount.label}</td>
      <td>
        {$form.square_min_amount.html}
        <div class="description">{ts}Card payments, and recurring installments, below this amount are refused on public forms before Square is asked to charge the card. Card testers usually try very small amounts, so if you never take payments under, say, 5.00, enter 5 here. Leave empty for no minimum. Staff on back-office forms are not limited.{/ts}</div>
      </td>
    </tr>
    <tr class="crm-square-settings-form-block-statement_description">
      <td class="label">{$form.square_statement_description.label}</td>
      <td>
        {$form.square_statement_description.html}
        <div class="description">{ts}Up to 20 characters that Square adds to the payer's card statement for one-time payments, after your business name (for example "DONATION"). Leave empty to use only what is set in your Square account. Square does not add it to subscription installments.{/ts}</div>
      </td>
    </tr>
  </table>
  <div class="crm-submit-buttons">
    {include file="CRM/common/formButtons.tpl" location="bottom"}
  </div>
</div>
{/crmScope}
