<?php

require_once dirname(__DIR__) . '/Core/Payment/Square/SquareUnitTestCase.php';

use Square\Core\Pagination\Page;
use Square\Core\Pagination\Pager;
use Square\SquareClient;
use Square\Types\CreateWebhookSubscriptionResponse;
use Square\Types\UpdateWebhookSubscriptionResponse;
use Square\Types\UpdateWebhookSubscriptionSignatureKeyResponse;
use Square\Types\WebhookSubscription;
use Square\Webhooks\Subscriptions\Requests\CreateWebhookSubscriptionRequest;
use Square\Webhooks\Subscriptions\Requests\UpdateWebhookSubscriptionRequest;
use Square\Webhooks\Subscriptions\SubscriptionsClient;
use Square\Webhooks\WebhooksClient;

/**
 * Finding, checking and repairing a processor's Square webhook subscription.
 */
class CRM_Square_WebhooksTest extends CRM_Core_Payment_Square_SquareUnitTestCase {

  private const URL = 'https://example.org/civicrm/payment/ipn/42';

  /**
   * The application's webhook subscriptions at (mocked) Square.
   *
   * @var \Square\Types\WebhookSubscription[]
   */
  private array $subscriptions = [];

  /**
   * Requests sent to (mocked) Square, as [method, request].
   *
   * @var array
   */
  private array $calls = [];

  /**
   * Signature keys saved on the processor.
   *
   * @var string[]
   */
  private array $savedKeys = [];

  protected function setUp(): void {
    parent::setUp();
    $this->subscriptions = [];
    $this->calls = [];
    $this->savedKeys = [];
  }

  public function testNoSubscriptionIsAProblem(): void {
    $this->subscriptions = [$this->subscription(['notificationUrl' => 'https://other.example.org/civicrm/payment/ipn/42'])];

    $problems = $this->webhooks()->getProblems();

    $this->assertCount(1, $problems);
    $this->assertStringContainsString('No Square webhook subscription sends events to ' . self::URL, $problems[0]);
  }

  public function testCompleteSubscriptionHasNoProblems(): void {
    $this->subscriptions = [$this->subscription()];

    $this->assertSame([], $this->webhooks()->getProblems());
  }

  public function testDisabledSubscriptionMissingEventsIsReported(): void {
    $this->subscriptions = [$this->subscription([
      'enabled' => FALSE,
      'eventTypes' => ['payment.updated', 'refund.updated'],
    ]),
    ];

    $problems = $this->webhooks()->getProblems();

    $this->assertStringContainsString('is disabled', $problems[0]);
    $this->assertStringContainsString('does not send these events: subscription.created', $problems[1]);
    $this->assertStringContainsString('card.updated', $problems[1]);
    $this->assertStringNotContainsString('payment.updated,', $problems[1]);
  }

  public function testEnabledSubscriptionIsPreferredOverADisabledOne(): void {
    $this->subscriptions = [
      $this->subscription(['id' => 'wbhk_old', 'enabled' => FALSE]),
      $this->subscription(['id' => 'wbhk_new']),
    ];

    $this->assertSame('wbhk_new', $this->webhooks()->find()->getId());
  }

  public function testWrongOrMissingSignatureKeyIsReported(): void {
    $this->subscriptions = [$this->subscription(['signatureKey' => 'square-key'])];

    $problems = $this->webhooks(['subject' => 'stale-key'])->getProblems();
    $this->assertStringContainsString('is not the one Square signs', $problems[0]);

    $problems = $this->webhooks(['subject' => ''])->getProblems();
    $this->assertStringContainsString('has no webhook signature key', $problems[0]);
  }

  public function testHttpUrlIsReported(): void {
    $problems = $this->webhooks([], 'http://example.org/civicrm/payment/ipn/42')->getProblems();

    $this->assertStringContainsString('Square only sends webhooks to https:// URLs', $problems[0]);
  }

  public function testFixCreatesAMissingSubscriptionAndSavesItsKey(): void {
    $done = $this->webhooks(['subject' => ''])->fix();

    [$method, $request] = $this->calls[0];
    $this->assertSame('create', $method);
    $this->assertInstanceOf(CreateWebhookSubscriptionRequest::class, $request);
    $this->assertSame(self::URL, $request->getSubscription()->getNotificationUrl());
    $this->assertTrue($request->getSubscription()->getEnabled());
    $this->assertSame(CRM_Core_Payment_SquareIPN::getSupportedEventTypes(), $request->getSubscription()->getEventTypes());
    $this->assertSame('CiviCRM example.org (processor 42)', $request->getSubscription()->getName());
    $this->assertSame(['created-key'], $this->savedKeys);
    $this->assertCount(2, $done);
    $this->assertCount(1, $this->calls, 'The new key needs no rotation.');
  }

  public function testFixEnablesAndCompletesAnExistingSubscriptionKeepingItsOtherEvents(): void {
    $this->subscriptions = [$this->subscription([
      'enabled' => FALSE,
      'eventTypes' => ['payment.updated', 'customer.created'],
      'signatureKey' => 'signature-key',
    ]),
    ];

    $this->webhooks()->fix();

    [$method, $request] = $this->calls[0];
    $this->assertSame('update', $method);
    $this->assertInstanceOf(UpdateWebhookSubscriptionRequest::class, $request);
    $this->assertSame('wbhk_1', $request->getSubscriptionId());
    $this->assertTrue($request->getSubscription()->getEnabled());
    $eventTypes = $request->getSubscription()->getEventTypes();
    $this->assertContains('customer.created', $eventTypes, 'Events the admin added are kept.');
    $this->assertSame([], array_diff(CRM_Core_Payment_SquareIPN::getSupportedEventTypes(), $eventTypes));
    $this->assertSame([], $this->savedKeys, 'The saved key already matches.');
    $this->assertCount(1, $this->calls);
  }

  public function testFixLeavesACompleteSubscriptionAlone(): void {
    $this->subscriptions = [$this->subscription()];

    $this->assertSame([], $this->webhooks()->fix());
    $this->assertSame([], $this->calls);
  }

  public function testFixGeneratesAKeyWhenTheProcessorHasNone(): void {
    $this->subscriptions = [$this->subscription()];

    $this->webhooks(['subject' => ''])->fix();

    $this->assertSame('updateSignatureKey', $this->calls[0][0]);
    $this->assertSame(['rotated-key'], $this->savedKeys);
  }

  public function testFixGeneratesAKeyWhenAskedTo(): void {
    $this->subscriptions = [$this->subscription()];

    $this->webhooks()->fix(TRUE);

    $this->assertSame(['rotated-key'], $this->savedKeys);
  }

  public function testFixSavesTheKeySquareSignsWith(): void {
    $this->subscriptions = [$this->subscription(['signatureKey' => 'square-key'])];

    $this->webhooks(['subject' => 'stale-key'])->fix();

    $this->assertSame([], $this->calls);
    $this->assertSame(['square-key'], $this->savedKeys);
  }

  public function testFixRefusesAnHttpUrl(): void {
    $this->expectException(CRM_Core_Exception::class);
    $this->expectExceptionMessage('Square only sends webhooks to https:// URLs');
    $this->webhooks([], 'http://example.org/civicrm/payment/ipn/42')->fix();
  }

  /**
   * A webhook subscription for this processor, with every event and the saved key.
   *
   * @param array $values
   *
   * @return \Square\Types\WebhookSubscription
   */
  private function subscription(array $values = []): WebhookSubscription {
    return new WebhookSubscription($values + [
      'id' => 'wbhk_1',
      'name' => 'CiviCRM',
      'enabled' => TRUE,
      'eventTypes' => CRM_Core_Payment_SquareIPN::getSupportedEventTypes(),
      'notificationUrl' => self::URL,
    ]);
  }

  /**
   * The webhook manager for processor 42, against the mocked Square.
   *
   * @param array $configOverrides
   * @param string $url
   *   The processor's notification URL.
   *
   * @return \CRM_Square_Webhooks
   */
  private function webhooks(array $configOverrides = [], string $url = self::URL): CRM_Square_Webhooks {
    $config = $this->processorConfig($configOverrides);
    $processor = $this->getMockBuilder(CRM_Core_Payment_Square::class)
      ->setConstructorArgs(['live', &$config])
      ->onlyMethods(['getWebhookNotificationUrl'])
      ->getMock();
    $processor->method('getWebhookNotificationUrl')->willReturn($url);

    $client = new SquareClient(token: 'test-token', options: ['baseUrl' => 'https://example.invalid']);
    $client->webhooks = $this->createMock(WebhooksClient::class);
    $client->webhooks->subscriptions = $this->subscriptionsClient();
    $gateway = new CRM_Square_Gateway($config, fn () => $client);

    $test = $this;
    return new class($processor, $gateway, $test) extends CRM_Square_Webhooks {

      /**
       * @var \CRM_Square_WebhooksTest
       */
      private $test;

      /**
       * @param \CRM_Core_Payment_Square $processor
       * @param \CRM_Square_Gateway $gateway
       * @param \CRM_Square_WebhooksTest $test
       */
      public function __construct($processor, $gateway, $test) {
        parent::__construct($processor, $gateway);
        $this->test = $test;
      }

      /**
       * @param string $key
       */
      protected function saveSignatureKey(string $key): void {
        $this->test->keySaved($key);
        $this->processor->setWebhookSignatureKey($key);
      }

    };
  }

  /**
   * Record a signature key saved on the processor.
   *
   * @param string $key
   */
  public function keySaved(string $key): void {
    $this->savedKeys[] = $key;
  }

  /**
   * A mocked Webhook Subscriptions API over $this->subscriptions.
   *
   * @return \Square\Webhooks\Subscriptions\SubscriptionsClient
   */
  private function subscriptionsClient(): SubscriptionsClient {
    $client = $this->createMock(SubscriptionsClient::class);
    $client->method('list')->willReturnCallback(fn () => new class($this->subscriptions) extends Pager {

      /**
       * @var array
       */
      private array $items;

      /**
       * @param array $items
       */
      public function __construct(array $items) {
        $this->items = $items;
      }

      /**
       * @return \Generator
       */
      public function getPages(): Generator {
        yield new Page($this->items);
      }

    });
    $client->method('create')->willReturnCallback(function ($request) {
      $this->calls[] = ['create', $request];
      $created = $request->getSubscription();
      return new CreateWebhookSubscriptionResponse([
        'subscription' => new WebhookSubscription([
          'id' => 'wbhk_created',
          'name' => $created->getName(),
          'enabled' => TRUE,
          'eventTypes' => $created->getEventTypes(),
          'notificationUrl' => $created->getNotificationUrl(),
          'signatureKey' => 'created-key',
        ]),
      ]);
    });
    $client->method('update')->willReturnCallback(function ($request) {
      $this->calls[] = ['update', $request];
      return new UpdateWebhookSubscriptionResponse([]);
    });
    $client->method('updateSignatureKey')->willReturnCallback(function ($request) {
      $this->calls[] = ['updateSignatureKey', $request];
      return new UpdateWebhookSubscriptionSignatureKeyResponse(['signatureKey' => 'rotated-key']);
    });
    return $client;
  }

}
