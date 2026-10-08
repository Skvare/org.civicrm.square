<?php

/**
 * Class CRM_Core_Payment_SquareOutcomeUnknownException.
 *
 * Thrown at checkout when Square may have charged the card but did not
 * confirm it: a 5xx or 429 after the SDK's own retries, a timeout, an
 * unreadable response, or a failure after Square accepted the charge.
 *
 * Deliberately not a PaymentProcessorException: on a contribution page that
 * would make CiviCRM mark the contribution Failed, while the payment.updated
 * webhook may yet show it was paid. As a plain CRM_Core_Exception the
 * contribution stays Pending, for the webhook to complete. Event
 * registration only catches PaymentProcessorException, so doPayment()
 * converts it for that component.
 */
class CRM_Core_Payment_SquareOutcomeUnknownException extends CRM_Core_Exception {

}
