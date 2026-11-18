{crmScope extensionKey='org.civicrm.square'}
<div class="crm-block crm-form-block crm-square-webhooks-form-block">
  <div class="help">
    <p>{ts}Square tells CiviCRM about payments, subscription installments, refunds and card changes by webhook. Each Square payment processor needs a webhook subscription, in the Square application whose access token it uses, that sends every event below to its own URL, signed with the key saved on the processor.{/ts}</p>
    <p>{ts}"Create / update webhooks" creates a missing subscription, or enables an existing one and adds the events it lacks, and saves its signature key on the processor. Square only sends webhooks to https:// URLs.{/ts}</p>
    {if $environment && $environment != 'Production'}
      <p><strong>{ts 1=$environment}This site's environment is %1: webhooks created here send this site's events to this site's URL, and the scheduled job that recovers missed events does not run.{/ts}</strong></p>
    {/if}
  </div>

  {if $rows}
    <table class="display">
      <thead>
        <tr>
          <th>{ts}Payment processor{/ts}</th>
          <th>{ts}Webhook URL{/ts}</th>
          <th>{ts}Status{/ts}</th>
          <th>{ts}Event search{/ts}</th>
        </tr>
      </thead>
      <tbody>
        {foreach from=$rows item=row}
          <tr class="{cycle values="odd-row,even-row"}">
            <td>{$row.label|escape}</td>
            <td><code>{$row.url|escape}</code></td>
            <td>
              {if $row.error}
                <span class="crm-error">{ts 1=$row.error|escape}Could not check with Square: %1{/ts}</span>
              {elseif $row.problems}
                <ul>
                  {foreach from=$row.problems item=problem}
                    <li class="crm-error">{$problem|escape}</li>
                  {/foreach}
                </ul>
              {else}
                <i class="crm-i fa-check" aria-hidden="true"></i> {ts}OK{/ts}
              {/if}
            </td>
            <td>
              {if $row.search_enabled_since}
                {ts 1=$row.search_enabled_since|crmDate}Enabled since %1{/ts}
              {else}
                {ts}Not enabled{/ts}
              {/if}
            </td>
          </tr>
        {/foreach}
      </tbody>
    </table>

    <p class="description">{ts}Events the webhook subscription must send:{/ts} <code>{$eventTypes|escape}</code></p>

    <table class="form-layout">
      <tr>
        <td class="label">{$form.enable_event_search.label}</td>
        <td>
          {$form.enable_event_search.html}
          <div class="description">{ts}Lets the "Square: recover missed webhook events" scheduled job fetch events whose webhooks never arrived (for example while the site was down). Square only finds events from after search is enabled, and for 28 days.{/ts}</div>
        </td>
      </tr>
      <tr>
        <td class="label">{$form.replace_signature_key.label}</td>
        <td>
          {$form.replace_signature_key.html}
          <div class="description">{ts}Only if CiviCRM's log shows "Square IPN: signature validation failed" for webhooks Square sent: Square then signs with a new key, which is saved on the processor.{/ts}</div>
        </td>
      </tr>
    </table>
    <div class="crm-submit-buttons">
      {include file="CRM/common/formButtons.tpl" location="bottom"}
    </div>
  {else}
    <p>{ts}There is no active Square payment processor.{/ts}</p>
  {/if}
</div>
{/crmScope}
