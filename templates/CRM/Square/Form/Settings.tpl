{crmScope extensionKey='org.civicrm.square'}
<div class="crm-block crm-form-block crm-square-settings-form-block">
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
  </table>
  <div class="crm-submit-buttons">
    {include file="CRM/common/formButtons.tpl" location="bottom"}
  </div>
</div>
{/crmScope}
