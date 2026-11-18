<?php

use Civi\Api4\Contact;

/**
 * Keeps Square customers' names and emails in step with their CiviCRM contacts.
 *
 * The post hook (square_civicrm_post()) queues a contact when it, or one of its emails, is
 * saved; at the end of the request each Square customer mapped to a queued
 * contact (on any Square processor) is updated from the contact's current
 * name and primary email. Square is only called for a customer whose
 * details changed since it was last updated (see
 * CRM_Square_Customers::syncFromContact()), and failures are only logged:
 * nothing a contact edit does depends on Square.
 */
class CRM_Square_CustomerSync {

  /**
   * Contacts to sync at the end of this request, as keys.
   *
   * @var array<int, true>
   */
  protected static array $queued = [];

  /**
   * The active Square processors, by ID, once looked up.
   *
   * @var array[]|null
   */
  protected ?array $activeProcessors = NULL;

  /**
   * Sync a contact's Square customers at the end of this request.
   *
   * @param int $contactId
   */
  public static function queue(int $contactId): void {
    if ($contactId <= 0 || isset(self::$queued[$contactId])) {
      return;
    }
    if (!self::$queued) {
      // After the contact edit's transaction has committed, and after the
      // page has been built: never in the way of the edit itself.
      register_shutdown_function([self::class, 'runQueued']);
    }
    self::$queued[$contactId] = TRUE;
  }

  /**
   * Sync every queued contact.
   */
  public static function runQueued(): void {
    $contactIds = array_keys(self::$queued);
    self::$queued = [];
    // Like scheduled jobs: a copy of a live site (e.g. staging) must not
    // change live Square customers in the background.
    $environment = (string) \Civi::settings()->get('environment');
    if ($environment !== '' && $environment !== 'Production') {
      return;
    }
    try {
      (new self())->sync($contactIds);
    }
    catch (\Throwable $e) {
      \Civi::log('square')->warning('Square: could not update Square customers after contact changes: ' . $e->getMessage());
    }
  }

  /**
   * Update the Square customers of some contacts.
   *
   * @param int[] $contactIds
   *
   * @return int
   *   How many Square customers were updated.
   */
  public function sync(array $contactIds): int {
    $updated = 0;
    foreach (array_chunk(array_unique(array_map('intval', $contactIds)), 500) as $chunk) {
      $mappings = $this->getMappings($chunk);
      if (!$mappings) {
        continue;
      }
      $contacts = $this->getContacts(array_unique(array_column($mappings, 'contact_id')));
      foreach ($mappings as $mapping) {
        $contactId = (int) $mapping['contact_id'];
        if (!isset($contacts[$contactId])) {
          continue;
        }
        try {
          $processor = $this->getProcessor((int) $mapping['payment_processor_id']);
          if ($processor && $processor->syncSquareCustomer((string) $mapping['square_customer_id'], $contactId, $contacts[$contactId])) {
            $updated++;
          }
        }
        catch (\Throwable $e) {
          \Civi::log('square')->warning("Square: could not update Square customer {$mapping['square_customer_id']} from contact {$contactId}: " . $e->getMessage());
        }
      }
    }
    return $updated;
  }

  /**
   * The Square customers mapped to some contacts.
   *
   * @param int[] $contactIds
   *
   * @return array[]
   *   contact_id, payment_processor_id and square_customer_id.
   */
  protected function getMappings(array $contactIds): array {
    if (!$contactIds) {
      return [];
    }
    $dao = CRM_Core_DAO::executeQuery('SELECT contact_id, payment_processor_id, square_customer_id FROM square_customer_map WHERE contact_id IN (' . implode(',', array_map('intval', $contactIds)) . ')');
    return $dao->fetchAll();
  }

  /**
   * The name and primary email of some contacts.
   *
   * @param int[] $contactIds
   *
   * @return array[]
   *   first_name, last_name and email, by contact ID.
   */
  protected function getContacts(array $contactIds): array {
    $contacts = [];
    $rows = Contact::get(FALSE)
      ->addSelect('id', 'first_name', 'last_name', 'email_primary.email')
      ->addWhere('id', 'IN', $contactIds)
      ->addWhere('is_deleted', '=', FALSE)
      ->execute();
    foreach ($rows as $row) {
      $contacts[(int) $row['id']] = [
        'first_name' => $row['first_name'] ?? NULL,
        'last_name' => $row['last_name'] ?? NULL,
        'email' => $row['email_primary.email'] ?? NULL,
      ];
    }
    return $contacts;
  }

  /**
   * An active Square payment processor.
   *
   * @param int $processorId
   *
   * @return \CRM_Core_Payment_Square|null
   *   NULL if it is not an active Square processor.
   */
  protected function getProcessor(int $processorId): ?CRM_Core_Payment_Square {
    $this->activeProcessors ??= CRM_Square_Processors::getActive();
    if (!isset($this->activeProcessors[$processorId])) {
      return NULL;
    }
    return CRM_Square_Processors::getObject($processorId);
  }

}
