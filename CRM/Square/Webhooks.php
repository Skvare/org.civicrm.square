<?php

use Civi\Api4\PaymentProcessor;
use CRM_Square_ExtensionUtil as E;
use Square\SquareClient;
use Square\Types\WebhookSubscription;
use Square\Webhooks\Subscriptions\Requests\CreateWebhookSubscriptionRequest;
use Square\Webhooks\Subscriptions\Requests\ListSubscriptionsRequest;
use Square\Webhooks\Subscriptions\Requests\UpdateWebhookSubscriptionRequest;
use Square\Webhooks\Subscriptions\Requests\UpdateWebhookSubscriptionSignatureKeyRequest;

/**
 * The Square webhook subscription that notifies one payment processor.
 *
 * Square sends webhooks to a subscription of the Square application whose
 * access token the processor uses. This finds the subscription for the
 * processor's notification URL, says what is wrong with it, and creates or
 * repairs it: enabled, sending every event CRM_Core_Payment_SquareIPN
 * handles, and signed with the key saved on the processor.
 *
 * The access token must be allowed to manage webhook subscriptions, as the
 * application's own (personal) access token is.
 */
class CRM_Square_Webhooks {

  /**
   * The payment processor Square notifies.
   *
   * @var \CRM_Core_Payment_Square
   */
  protected CRM_Core_Payment_Square $processor;

  /**
   * Square API access for the payment processor.
   *
   * @var \CRM_Square_Gateway
   */
  protected CRM_Square_Gateway $gateway;

  /**
   * Builds the webhook manager for one payment processor.
   *
   * @param \CRM_Core_Payment_Square $processor
   * @param \CRM_Square_Gateway $gateway
   */
  public function __construct(CRM_Core_Payment_Square $processor, CRM_Square_Gateway $gateway) {
    $this->processor = $processor;
    $this->gateway = $gateway;
  }

  /**
   * The event types the subscription must send.
   *
   * @return string[]
   */
  public static function requiredEventTypes(): array {
    return CRM_Core_Payment_SquareIPN::getSupportedEventTypes();
  }

  /**
   * The URL Square must send this processor's webhooks to.
   *
   * @return string
   */
  public function getNotificationUrl(): string {
    return $this->processor->getWebhookNotificationUrl();
  }

  /**
   * The name a subscription created here gets in the Square Developer Dashboard.
   *
   * @return string
   */
  public function getSubscriptionName(): string {
    $name = sprintf('CiviCRM %s (processor %d%s)', parse_url($this->getNotificationUrl(), PHP_URL_HOST) ?: 'site', $this->gateway->processorId(), $this->gateway->isTestMode() ? ', test' : '');
    // Square limits names to 64 characters.
    return mb_substr($name, 0, 64);
  }

  /**
   * The application's webhook subscriptions, disabled ones included.
   *
   * @return \Square\Types\WebhookSubscription[]
   *
   * @throws \CRM_Core_Exception
   */
  protected function listSubscriptions(): array {
    // The pager calls Square as it is iterated, so iterate inside call().
    return $this->gateway->call(fn (SquareClient $client) => iterator_to_array(
      $client->webhooks->subscriptions->list(new ListSubscriptionsRequest(['includeDisabled' => TRUE])),
      FALSE
    ));
  }

  /**
   * The subscription that sends webhooks to this processor's URL, if any.
   *
   * An enabled one is preferred over a disabled one.
   *
   * @return \Square\Types\WebhookSubscription|null
   *
   * @throws \CRM_Core_Exception
   */
  public function find(): ?WebhookSubscription {
    $url = $this->getNotificationUrl();
    $disabled = NULL;
    foreach ($this->listSubscriptions() as $subscription) {
      if ((string) $subscription->getNotificationUrl() !== $url) {
        continue;
      }
      if ($subscription->getEnabled()) {
        return $subscription;
      }
      $disabled ??= $subscription;
    }
    return $disabled;
  }

  /**
   * What is wrong with this processor's webhook.
   *
   * @return string[]
   *   Translated descriptions of each problem; empty if there is none.
   *
   * @throws \CRM_Core_Exception
   *   If Square cannot be asked.
   */
  public function getProblems(): array {
    $url = $this->getNotificationUrl();
    if ($url === '') {
      return [E::ts('CiviCRM cannot work out the URL Square should send webhooks to. Check the site URL (CIVICRM_UF_BASEURL).')];
    }
    $problems = [];
    if (!$this->isHttps($url)) {
      $problems[] = E::ts('Square only sends webhooks to https:// URLs, but this processor\'s webhook URL is %1.', [1 => $url]);
    }

    $subscription = $this->find();
    if (!$subscription) {
      $problems[] = E::ts('No Square webhook subscription sends events to %1, so CiviCRM is not told about payments, installments or refunds.', [1 => $url]);
      return $problems;
    }

    $name = (string) ($subscription->getName() ?: $subscription->getId());
    if (!$subscription->getEnabled()) {
      $problems[] = E::ts('The Square webhook subscription "%1" for %2 is disabled.', [1 => $name, 2 => $url]);
    }
    $missing = $this->getMissingEventTypes($subscription);
    if ($missing) {
      $problems[] = E::ts('The Square webhook subscription "%1" does not send these events: %2.', [
        1 => $name,
        2 => implode(', ', $missing),
      ]);
    }

    $savedKey = $this->getSavedSignatureKey();
    $squareKey = (string) $subscription->getSignatureKey();
    if ($savedKey === '') {
      $problems[] = E::ts('The payment processor has no webhook signature key, so CiviCRM refuses every webhook Square sends.');
    }
    elseif ($squareKey !== '' && !hash_equals($squareKey, $savedKey)) {
      $problems[] = E::ts('The webhook signature key saved on the payment processor is not the one Square signs "%1" with, so CiviCRM refuses its webhooks.', [1 => $name]);
    }
    return $problems;
  }

  /**
   * Create or repair this processor's webhook subscription.
   *
   * Creates it if there is none; otherwise enables it and adds any missing
   * event types (keeping any others it sends). Saves its signature key on
   * the processor when Square gives one: always for a new subscription;
   * for an existing one, a new key is generated only when the processor
   * has none, or when $replaceSignatureKey is set (e.g. after "signature
   * validation failed" errors).
   *
   * @param bool $replaceSignatureKey
   *
   * @return string[]
   *   Translated descriptions of what was done.
   *
   * @throws \CRM_Core_Exception
   */
  public function fix(bool $replaceSignatureKey = FALSE): array {
    $url = $this->getNotificationUrl();
    if ($url === '' || !$this->isHttps($url)) {
      throw new CRM_Core_Exception(E::ts('Square only sends webhooks to https:// URLs, but this processor\'s webhook URL is "%1".', [1 => $url]));
    }

    $done = [];
    $subscription = $this->find();
    if (!$subscription) {
      $subscription = $this->gateway->call(fn (SquareClient $client) => $client->webhooks->subscriptions->create(new CreateWebhookSubscriptionRequest([
        // Never retried automatically, and a deleted subscription must be
        // re-creatable, so a fresh key.
        'idempotencyKey' => bin2hex(random_bytes(16)),
        'subscription' => new WebhookSubscription([
          'name' => $this->getSubscriptionName(),
          'enabled' => TRUE,
          'eventTypes' => self::requiredEventTypes(),
          'notificationUrl' => $url,
        ]),
      ])))->getSubscription();
      if (empty($subscription) || empty($subscription->getId())) {
        throw new CRM_Core_Exception('Square did not return the webhook subscription it created.');
      }
      $done[] = E::ts('Created the Square webhook subscription "%1" for %2.', [1 => $subscription->getName(), 2 => $url]);
      if ((string) $subscription->getSignatureKey() !== '') {
        $this->saveSignatureKey((string) $subscription->getSignatureKey());
        $done[] = E::ts('Saved its signature key on the payment processor.');
        return $done;
      }
      // Square did not return the key: generate one it does return.
      $replaceSignatureKey = TRUE;
    }
    else {
      $changes = [];
      if (!$subscription->getEnabled()) {
        $changes['enabled'] = TRUE;
      }
      if ($this->getMissingEventTypes($subscription)) {
        $changes['eventTypes'] = array_values(array_unique(array_merge($subscription->getEventTypes() ?? [], self::requiredEventTypes())));
      }
      if ($changes) {
        $this->gateway->call(fn (SquareClient $client) => $client->webhooks->subscriptions->update(new UpdateWebhookSubscriptionRequest([
          'subscriptionId' => (string) $subscription->getId(),
          'subscription' => new WebhookSubscription($changes),
        ])));
        $done[] = E::ts('Updated the Square webhook subscription "%1": enabled, and sending every event CiviCRM needs.', [1 => $subscription->getName()]);
      }
    }

    $squareKey = (string) $subscription->getSignatureKey();
    if ($replaceSignatureKey || $this->getSavedSignatureKey() === '') {
      $newKey = (string) $this->gateway->call(fn (SquareClient $client) => $client->webhooks->subscriptions->updateSignatureKey(new UpdateWebhookSubscriptionSignatureKeyRequest([
        'subscriptionId' => (string) $subscription->getId(),
        'idempotencyKey' => bin2hex(random_bytes(16)),
      ])))->getSignatureKey();
      if ($newKey === '') {
        throw new CRM_Core_Exception('Square did not return a new signature key.');
      }
      $this->saveSignatureKey($newKey);
      $done[] = E::ts('Generated a new signature key for the Square webhook subscription "%1", and saved it on the payment processor.', [1 => $subscription->getName()]);
    }
    elseif ($squareKey !== '' && !hash_equals($squareKey, $this->getSavedSignatureKey())) {
      $this->saveSignatureKey($squareKey);
      $done[] = E::ts('Saved the signature key Square signs "%1" with on the payment processor.', [1 => $subscription->getName()]);
    }
    return $done;
  }

  /**
   * The required event types a subscription does not send.
   *
   * @param \Square\Types\WebhookSubscription $subscription
   *
   * @return string[]
   */
  protected function getMissingEventTypes(WebhookSubscription $subscription): array {
    return array_values(array_diff(self::requiredEventTypes(), $subscription->getEventTypes() ?? []));
  }

  /**
   * The webhook signature key saved on the processor.
   *
   * @return string
   */
  protected function getSavedSignatureKey(): string {
    return trim((string) $this->processor->getWebhookSignatureKey());
  }

  /**
   * Save a webhook signature key on the processor.
   *
   * @param string $key
   *
   * @throws \CRM_Core_Exception
   */
  protected function saveSignatureKey(string $key): void {
    PaymentProcessor::update(FALSE)
      ->addWhere('id', '=', $this->gateway->processorId())
      ->addValue('subject', $key)
      ->execute();
    $this->processor->setWebhookSignatureKey($key);
  }

  /**
   * Whether a URL is https, as Square requires.
   *
   * @param string $url
   *
   * @return bool
   */
  protected function isHttps(string $url): bool {
    return strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https';
  }

}
