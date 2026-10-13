<?php

/**
 * Class CRM_Core_Payment_SquareDebugLogger.
 *
 * Centralised debug logger for the Square payment processor extension.
 *
 * All verbose debug tracing in CRM_Core_Payment_Square and
 * CRM_Core_Payment_SquareIPN is routed through here rather than calling
 * Civi::log('square')->debug() directly, so it can be toggled on/off via the
 * "square_ipn_debug_logging" setting (Administer > System Settings > Square
 * IPN Debug Logging) instead of always writing to ConfigAndLog.
 *
 * Civi::log('square')->error() calls are unaffected and always log.
 */
class CRM_Core_Payment_SquareDebugLogger {

  /**
   * Log a debug message only if Square IPN debug logging is enabled.
   *
   * @param string $message
   * @param array $context
   */
  public static function log(string $message, array $context = []): void {
    if (!\Civi::settings()->get('square_ipn_debug_logging')) {
      return;
    }
    \Civi::log('square')->debug($message, $context);
  }

}
