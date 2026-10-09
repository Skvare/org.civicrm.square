{*
 * Billing block template for Square Web Payments.
 *
 * The inline <script> publishes this processor's settings for square.js.
 * CRM_Core_Resources::addSetting() also does, but its settings are dropped
 * when the billing block is loaded as a snippet=4 AJAX response (e.g. by
 * Drupal Webforms). It runs synchronously, not in a CRM.$(fn) ready
 * handler: jQuery 3 defers those, so square.js — re-run in the same AJAX
 * response — would look for the settings before they were set.
 *
 * The #crm-payment-js-billing-form-container wrapper is required by
 * CRM.squarePayment.getBillingForm() to locate the parent <form> element.
 *}
<script type="text/javascript">
  window.CRM = window.CRM || {ldelim}{rdelim};
  CRM.vars = CRM.vars || {ldelim}{rdelim};
  CRM.vars.orgCivicrmSquare = {$squareJSVarsJson nofilter};
</script>
{crmScope extensionKey='org.civicrm.square'}
<div id="crm-payment-js-billing-form-container" class="square-payment-container">
  <div id="square-card-container" style="display:none;"></div>
  <div id="square-card-errors" role="alert" class="crm-error messages error" style="display:none;"></div>
</div>
{/crmScope}
