<?php

require_once __DIR__ . '/SquareUnitTestCase.php';

use Civi\Payment\Exception\PaymentProcessorException;
use Square\Exceptions\SquareException;
use Square\SquareClient;
use Square\Subscriptions\Requests\UpdateSubscriptionRequest;
use Square\Subscriptions\SubscriptionsClient;
use Square\Types\GetSubscriptionResponse;
use Square\Types\Subscription;
use Square\Types\UpdateSubscriptionResponse;

/**
 * CiviCRM's "update billing details" contract, updateSubscriptionBillingInfo().
 *
 * A donor replaces the card a Square subscription charges.
 */
class CRM_Core_Payment_Square_UpdateBillingInfoTest extends CRM_Core_Payment_Square_SquareUnitTestCase {

  /**
   * Recurring contribution 45: contact 7's, linked to SUB-1.
   */
  private const RECUR = ['processor_id' => 'SUB-1', 'contact_id' => 7];

  /**
   * Subscription update requests sent to Square.
   *
   * @var \Square\Subscriptions\Requests\UpdateSubscriptionRequest[]
   */
  private array $updates = [];

  /**
   * Arguments of each createCardOnFile() call.
   *
   * @var array
   */
  private array $cardsSaved = [];

  /**
   * The [recurring contribution ID, card ID] of each saveRecurCard() call.
   *
   * @var array
   */
  private array $recurCards = [];

  /**
   * Thrown by createCardOnFile(), if set: e.g. Square declined the card.
   *
   * @var \Throwable|null
   */
  private ?\Throwable $cardFailure = NULL;

  /**
   * Thrown by saveRecurCard(), if set.
   *
   * @var \Throwable|null
   */
  private ?\Throwable $recurCardFailure = NULL;

  protected function setUp(): void {
    parent::setUp();
    Civi::$logged = [];
  }

  public function testNewCardIsSavedToTheSubscriptionsCustomerAndCharged(): void {
    $message = '';

    $this->assertTrue($this->processor()->updateSubscriptionBillingInfo($message, $this->formParams()));

    $this->assertCount(1, $this->cardsSaved);
    [$customerId, $nonce, $params, $contactId] = $this->cardsSaved[0];
    $this->assertSame('CUST-1', $customerId, "The subscription's customer: Square charges only its own cards.");
    $this->assertSame('cnon:new-card', $nonce);
    $this->assertSame(7, $contactId);
    $this->assertSame('Pat', $params['first_name'], 'With the billing name and address.');
    $this->assertArrayNotHasKey('contributionRecurID', $params, 'Not linked to the recurring contribution before Square charges it.');

    $this->assertCount(1, $this->updates);
    $this->assertSame('SUB-1', $this->updates[0]->getSubscriptionId());
    $this->assertSame('ccof:new-card', $this->updates[0]->getSubscription()->getCardId());
    $this->assertSame(3, $this->updates[0]->getSubscription()->getVersion());
    $this->assertNull($this->updates[0]->getSubscription()->getPriceOverrideMoney(), 'Only the card changes.');

    $this->assertSame([[45, 'ccof:new-card']], $this->recurCards);
  }

  public function testCardIsRequired(): void {
    $this->expectRefusal('new card are required');
    $message = '';
    $this->processor()->updateSubscriptionBillingInfo($message, $this->formParams(['square_payment_token' => '']));
  }

  /**
   * Only the Square subscription linked to this recurring contribution is changed.
   *
   * @dataProvider unlinkedRecurProvider
   */
  public function testRecurNotLinkedToTheSubscriptionIsRefused(?array $recur): void {
    $this->expectRefusal('not linked to that Square subscription');
    $message = '';
    $this->processor($recur)->updateSubscriptionBillingInfo($message, $this->formParams());
  }

  public static function unlinkedRecurProvider(): array {
    return [
      "another processor's recurring contribution" => [NULL],
      'a different subscription' => [['processor_id' => 'SUB-OTHER', 'contact_id' => 7]],
    ];
  }

  public function testDeclinedCardChangesNothing(): void {
    $this->cardFailure = new PaymentProcessorException('Card declined.');

    try {
      $message = '';
      $this->processor()->updateSubscriptionBillingInfo($message, $this->formParams());
      $this->fail('Expected a PaymentProcessorException.');
    }
    catch (PaymentProcessorException $e) {
      $this->assertSame($this->cardFailure, $e, 'As Square reported it.');
    }
    $this->assertSame([], $this->updates);
    $this->assertSame([], $this->recurCards);
  }

  /**
   * The recurring contribution keeps the card Square still charges.
   */
  public function testSquareFailingToChangeTheCardIsReportedToTheForm(): void {
    $subscriptions = $this->subscriptionsClient();
    $subscriptions->method('update')->willThrowException(new SquareException('connection timed out'));

    try {
      $message = '';
      $this->processor(self::RECUR, $subscriptions)->updateSubscriptionBillingInfo($message, $this->formParams());
      $this->fail('Expected a PaymentProcessorException.');
    }
    catch (PaymentProcessorException $e) {
      $this->assertStringContainsString('Failed to update the card', $e->getMessage());
      $this->assertStringContainsString('connection timed out', $e->getMessage());
    }
    $this->assertSame([], $this->recurCards);
  }

  /**
   * Square charges the new card already, so the donor is not told it failed.
   */
  public function testPaymentTokenFailureAfterTheChangeIsLogged(): void {
    $this->recurCardFailure = new CRM_Core_Exception('Database unavailable.');
    $message = '';

    $this->assertTrue($this->processor()->updateSubscriptionBillingInfo($message, $this->formParams()));

    $this->assertCount(1, $this->updates);
    $this->assertSame('error', Civi::$logged[0][0] ?? NULL);
    $this->assertStringContainsString('now charges card ccof:new-card', Civi::$logged[0][1]);
  }

  /**
   * @param string $message
   */
  private function expectRefusal(string $message): void {
    $this->expectException(PaymentProcessorException::class);
    $this->expectExceptionMessage($message);
  }

  /**
   * What CRM_Contribute_Form_UpdateBilling passes, with the token js/square.js adds.
   *
   * @param array $overrides
   *
   * @return array
   */
  private function formParams(array $overrides = []): array {
    return array_replace([
      'first_name' => 'Pat',
      'last_name' => 'Doe',
      'street_address' => '1 Main St',
      'city' => 'Springfield',
      'postal_code' => '12345',
      'square_payment_token' => 'cnon:new-card',
      'subscriptionId' => 'SUB-1',
      'recurProcessorID' => 'SUB-1',
      'amount' => '19.00',
      'contributionRecurID' => 45,
    ], $overrides);
  }

  /**
   * A Square subscriptions client for SUB-1, of customer CUST-1, at version 3.
   *
   * @return \Square\Subscriptions\SubscriptionsClient&\PHPUnit\Framework\MockObject\MockObject
   */
  private function subscriptionsClient(): SubscriptionsClient {
    $subscriptions = $this->createMock(SubscriptionsClient::class);
    $subscriptions->method('get')->willReturn(new GetSubscriptionResponse([
      'subscription' => new Subscription(['id' => 'SUB-1', 'customerId' => 'CUST-1', 'version' => 3]),
    ]));
    return $subscriptions;
  }

  /**
   * A processor whose recurring contribution 45, of contact 7, is linked to SUB-1.
   *
   * @param array|null $recur
   *   Recurring contribution 45, or NULL if it belongs to another processor.
   * @param \Square\Subscriptions\SubscriptionsClient|null $subscriptions
   *
   * @return \CRM_Core_Payment_Square
   */
  private function processor(?array $recur = self::RECUR, ?SubscriptionsClient $subscriptions = NULL): CRM_Core_Payment_Square {
    if ($subscriptions === NULL) {
      $subscriptions = $this->subscriptionsClient();
      $subscriptions->method('update')->willReturnCallback(function (UpdateSubscriptionRequest $request) {
        $this->updates[] = $request;
        return new UpdateSubscriptionResponse(['subscription' => $request->getSubscription()]);
      });
    }
    $client = new SquareClient(token: 'test-token', options: ['baseUrl' => 'https://example.invalid']);
    $client->subscriptions = $subscriptions;

    return new class($this->processorConfig(), $client, $recur, $this) extends CRM_Core_Payment_Square {

      /**
       * @var \Square\SquareClient
       */
      private SquareClient $mockClient;

      /**
       * @var array|null
       */
      private ?array $recur;

      /**
       * The test, which records the cards saved.
       *
       * @var \CRM_Core_Payment_Square_UpdateBillingInfoTest
       */
      private CRM_Core_Payment_Square_UpdateBillingInfoTest $test;

      /**
       * @param array $paymentProcessor
       * @param \Square\SquareClient $mockClient
       * @param array|null $recur
       * @param \CRM_Core_Payment_Square_UpdateBillingInfoTest $test
       */
      public function __construct(array $paymentProcessor, SquareClient $mockClient, ?array $recur, CRM_Core_Payment_Square_UpdateBillingInfoTest $test) {
        parent::__construct('live', $paymentProcessor);
        $this->mockClient = $mockClient;
        $this->recur = $recur;
        $this->test = $test;
      }

      /**
       * @return \Square\SquareClient
       */
      protected function buildSquareClient(): SquareClient {
        return $this->mockClient;
      }

      /**
       * @return \CRM_Square_Customers
       */
      protected function customers(): CRM_Square_Customers {
        return new class($this->gateway(), $this->test) extends CRM_Square_Customers {

          /**
           * @var \CRM_Core_Payment_Square_UpdateBillingInfoTest
           */
          private CRM_Core_Payment_Square_UpdateBillingInfoTest $test;

          /**
           * @param \CRM_Square_Gateway $gateway
           * @param \CRM_Core_Payment_Square_UpdateBillingInfoTest $test
           */
          public function __construct(CRM_Square_Gateway $gateway, CRM_Core_Payment_Square_UpdateBillingInfoTest $test) {
            parent::__construct($gateway);
            $this->test = $test;
          }

          /**
           * @return string
           */
          public function createCardOnFile($customerId, $cardNonce, array $params = [], $contactId = NULL) {
            return $this->test->saveCard($customerId, $cardNonce, $params, $contactId);
          }

        };
      }

      /**
       * @param int $recurId
       *
       * @return array|null
       */
      protected function getRecurForCardUpdate(int $recurId): ?array {
        return $recurId === 45 ? $this->recur : NULL;
      }

      /**
       * Record instead of saving.
       */
      protected function saveRecurCard(int $recurId, string $cardId): void {
        $this->test->saveRecurCard($recurId, $cardId);
      }

    };
  }

  /**
   * Stands in for CRM_Square_Customers::createCardOnFile().
   *
   * @return string
   *   Square card ID.
   */
  public function saveCard(string $customerId, string $cardNonce, array $params, $contactId): string {
    if ($this->cardFailure) {
      throw $this->cardFailure;
    }
    $this->cardsSaved[] = [$customerId, $cardNonce, $params, $contactId];
    return 'ccof:new-card';
  }

  /**
   * Stands in for CRM_Core_Payment_Square::saveRecurCard().
   */
  public function saveRecurCard(int $recurId, string $cardId): void {
    if ($this->recurCardFailure) {
      throw $this->recurCardFailure;
    }
    $this->recurCards[] = [$recurId, $cardId];
  }

}
