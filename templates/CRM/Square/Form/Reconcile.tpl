{crmScope extensionKey='org.civicrm.square'}
<div class="crm-block crm-form-block crm-square-reconcile-form-block">
  <div class="help">
    <p>{ts}Compare what Square recorded with what CiviCRM recorded, and process what CiviCRM missed. Processing uses Square's current data, exactly as its webhook would have been processed, so it never records anything twice; each one is added to the <a href="{$queueUrl}">webhook queue</a> as a record whose event ID starts "civicrm-reconcile-". Square cannot be asked to send a webhook again.{/ts}</p>
  </div>

  <table class="form-layout-compressed">
    <tr>
      <td class="label">{$form.pp.label}</td>
      <td>{$form.pp.html}</td>
    </tr>
    <tr>
      <td class="label">{$form.lookup.label}</td>
      <td>{$form.lookup.html}</td>
    </tr>
    <tr class="square-lookup square-lookup-subscription">
      <td class="label">{$form.ref.label}</td>
      <td>{$form.ref.html}</td>
    </tr>
    <tr class="square-lookup square-lookup-id">
      <td class="label">{$form.sid.label}</td>
      <td>{$form.sid.html}</td>
    </tr>
    <tr class="square-lookup square-lookup-range">
      <td class="label">{$form.type.label}</td>
      <td>{$form.type.html}</td>
    </tr>
    <tr class="square-lookup square-lookup-range square-lookup-events">
      <td class="label">{$form.from.label}</td>
      <td>{$form.from.html} {$form.to.label} {$form.to.html}</td>
    </tr>
    <tr>
      <td></td>
      <td>{$form.buttons.$searchButton.html}</td>
    </tr>
  </table>

  {if $searched}
    {if $searchError}
      <div class="messages status crm-error">{ts 1=$searchError|escape}Could not search Square: %1{/ts}</div>
    {elseif !$rows}
      <div class="messages status no-popup">{ts}Square has nothing matching this search.{/ts}</div>
    {else}
      {if $truncated}
        <div class="messages status no-popup">{ts 1=$maxRows}Only the first %1 are shown: narrow the search to see the rest.{/ts}</div>
      {/if}
      {if $eventSearchDisabled}
        <div class="messages status no-popup">{ts}Square event search was never enabled from CiviCRM, so Square may find no events: enable it at Administer » System Settings » Square Webhooks.{/ts}</div>
      {/if}
      <table class="display crm-square-reconcile-results">
        <thead>
          <tr>
            <th><input type="checkbox" class="square-select-all" title="{ts}Select all{/ts}" aria-label="{ts}Select all{/ts}"></th>
            <th>{ts}Type{/ts}</th>
            <th>{ts}Square{/ts}</th>
            <th>{ts}Date{/ts}</th>
            <th>{ts}Amount{/ts}</th>
            <th>{ts}Square status{/ts}</th>
            <th>{ts}In CiviCRM{/ts}</th>
            <th>{ts}Webhook queue{/ts}</th>
          </tr>
        </thead>
        <tbody>
          {foreach from=$rows item=row}
            <tr class="{cycle values="odd-row,even-row"} square-state-{$row.civicrm_state|escape}">
              <td>
                {if $row.processable || $row.retryable}
                  <input type="checkbox" class="square-select" name="square_select[]" value="{$row.key|escape}" aria-label="{ts 1=$row.square_id|escape}Select %1{/ts}">
                {/if}
              </td>
              <td>{$row.type|escape}</td>
              <td><code>{$row.square_id|escape}</code><br><span class="description">{$row.detail|escape}</span></td>
              <td>{$row.date|escape}</td>
              <td>{$row.amount|escape}</td>
              <td>{$row.square_status|escape}</td>
              <td>
                {if $row.civicrm_url}<a href="{$row.civicrm_url}">{$row.civicrm_label|escape}</a>{else}{$row.civicrm_label|escape}{/if}
                {if $row.note}<br><span class="description">{$row.note|escape}</span>{/if}
              </td>
              <td>
                {if $row.queue_status}
                  {$row.queue_status|escape}{if $row.queue_manual} ({ts}from this page{/ts}){/if}
                  {if $row.queue_message && $row.queue_status != 'success'}<br><span class="description">{$row.queue_message|escape}</span>{/if}
                {else}
                  {ts}never received{/ts}
                {/if}
              </td>
            </tr>
          {/foreach}
        </tbody>
      </table>
      <p class="description">{ts}"Process selected" processes the selected records from Square's current data. "Retry selected webhook queue records" processes the webhooks CiviCRM already received for them again (those that failed or are waiting), once the cause of the failure is fixed.{/ts}</p>
      <div class="crm-submit-buttons">
        {$form.buttons.$processButton.html}
        {$form.buttons.$retryButton.html}
      </div>
    {/if}
  {/if}
</div>
{literal}
<script type="text/javascript">
CRM.$(function($) {
  var $form = $('.crm-square-reconcile-form-block');
  function showLookup() {
    var lookup = $form.find('input[name=lookup]:checked').val();
    $form.find('.square-lookup').hide();
    $form.find('.square-lookup-' + lookup).show();
  }
  $form.on('change', 'input[name=lookup]', showLookup);
  showLookup();
  $form.on('change', '.square-select-all', function() {
    $form.find('.square-select').prop('checked', $(this).prop('checked'));
  });
});
</script>
{/literal}
{/crmScope}
