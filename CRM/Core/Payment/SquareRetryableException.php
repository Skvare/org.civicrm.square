<?php

/**
 * Class CRM_Core_Payment_SquareRetryableException.
 *
 * Marks a webhook-processing failure as transient (network error, Square
 * API 5xx/429, or a prerequisite CiviCRM record that may simply not exist
 * yet because webhooks arrived out of order) so
 * CRM_Core_Payment_SquareIPN::processQueuedWebhookEvent() can leave the
 * queued event in the "new" state for the mjwshared
 * Job.process_paymentprocessor_webhooks scheduled job to retry, instead of
 * marking it "error" (a dead end — that job only ever re-picks "new" rows).
 *
 * Anything else (malformed payload, reconciliation mismatch, permanently
 * unsupported event) should throw a plain CRM_Core_Exception, which is
 * treated as non-retryable.
 */
class CRM_Core_Payment_SquareRetryableException extends CRM_Core_Exception {

}
