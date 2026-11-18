<?php

use Civi\Payment\System;
use Civi\Api4\PaymentProcessor;
use CRM_Square_ExtensionUtil as E;

/**
 * The Square payment processors of the current domain.
 */
class CRM_Square_Processors {

  /**
   * The active Square payment processors, live and test.
   *
   * @param int|null $id
   *   Only this one.
   *
   * @return array[]
   *   civicrm_payment_processor rows (id, name, title, is_test), by ID.
   */
  public static function getActive(?int $id = NULL): array {
    $query = PaymentProcessor::get(FALSE)
      ->addSelect('id', 'name', 'title', 'is_test')
      ->addWhere('payment_processor_type_id:name', '=', 'Square')
      ->addWhere('is_active', '=', TRUE)
      ->addWhere('domain_id', '=', CRM_Core_Config::domainID())
      ->addWhere('is_test', 'IN', [TRUE, FALSE])
      ->addOrderBy('is_test')
      ->addOrderBy('id');
    if ($id) {
      $query->addWhere('id', '=', $id);
    }
    return $query->execute()->indexBy('id')->getArrayCopy();
  }

  /**
   * The payment processor object for a Square processor.
   *
   * @param int $id
   *
   * @return \CRM_Core_Payment_Square
   *
   * @throws \CRM_Core_Exception
   */
  public static function getObject(int $id): CRM_Core_Payment_Square {
    $processor = System::singleton()->getById($id);
    if (!$processor instanceof CRM_Core_Payment_Square) {
      throw new CRM_Core_Exception("Payment processor {$id} is not a Square payment processor.");
    }
    return $processor;
  }

  /**
   * A processor's name for messages, e.g. "Square (test)".
   *
   * @param array $processor
   *   A row from getActive().
   *
   * @return string
   */
  public static function label(array $processor): string {
    $name = (string) (($processor['title'] ?? '') ?: ($processor['name'] ?? '') ?: $processor['id']);
    return empty($processor['is_test']) ? $name : E::ts('%1 (test)', [1 => $name]);
  }

}
