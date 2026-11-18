<?php

/**
 * @file
 * Square.recoverevents API: receive Square events whose webhooks never arrived.
 *
 * Run hourly by the "Square: recover missed webhook events" scheduled job
 * (managed/Job_SquareRecoverEvents.mgd.php). See CRM_Square_EventRecovery.
 */

/**
 * Square.recoverevents API specification.
 *
 * @param array $spec
 *   Description of fields supported by this API call.
 */
function _civicrm_api3_square_recoverevents_spec(&$spec) {
  $spec['payment_processor_id'] = [
    'title' => 'Payment Processor ID',
    'description' => 'Only this Square payment processor (live or test). Default: every active one.',
    'type' => CRM_Utils_Type::T_INT,
  ];
  $spec['since'] = [
    'title' => 'Look back to',
    'description' => 'How far back to look, as a date or relative time such as "-3 days". Square keeps events for 28 days.',
    'type' => CRM_Utils_Type::T_STRING,
    'api.default' => '-3 days',
  ];
  $spec['limit'] = [
    'title' => 'Limit',
    'description' => 'The most events to look at per payment processor.',
    'type' => CRM_Utils_Type::T_INT,
    'api.default' => 500,
  ];
}

/**
 * Square.recoverevents API.
 *
 * @param array $params
 *
 * @return array
 *   API result: values keyed by payment processor ID, each the counts of
 *   CRM_Square_EventRecovery::recover(), or an error.
 */
function civicrm_api3_square_recoverevents($params) {
  $results = [];
  $hasErrors = FALSE;
  $processorIds = array_keys(CRM_Square_Processors::getActive(empty($params['payment_processor_id']) ? NULL : (int) $params['payment_processor_id']));
  foreach ($processorIds as $processorId) {
    try {
      $recovery = CRM_Square_Processors::getObject($processorId)->eventRecovery();
      if ($recovery->getSearchEnabledSince() === NULL) {
        // Square would find nothing: only events after search is enabled
        // are searchable. The system check says how to enable it.
        $results[$processorId] = ['skipped' => 'Square event search is not enabled for this payment processor. Enable it at Administer > System Settings > Square Webhooks.'];
        continue;
      }
      $results[$processorId] = $recovery->recover((string) $params['since'], (int) $params['limit']);
      $results[$processorId]['search_enabled_since'] = $recovery->getSearchEnabledSince();
    }
    catch (\Throwable $e) {
      $hasErrors = TRUE;
      $results[$processorId] = ['error' => $e->getMessage()];
      \Civi::log('square')->error("Square: could not recover events for payment processor {$processorId}: " . $e->getMessage());
    }
  }

  if ($hasErrors) {
    // The scheduled job log shows the message; the values say which failed.
    throw new CRM_Core_Exception('Square event recovery failed for at least one payment processor: ' . json_encode($results), 0, ['values' => $results]);
  }
  return civicrm_api3_create_success($results, $params, 'Square', 'recoverevents');
}
