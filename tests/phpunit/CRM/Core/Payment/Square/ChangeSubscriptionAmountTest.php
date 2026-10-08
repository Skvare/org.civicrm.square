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
 * CiviCRM's "change recurring amount" contract, changeSubscriptionAmount().
 */
class CRM_Core_Payment_Square_ChangeSubscriptionAmountTest extends CRM_Core_Payment_Square_SquareUnitTestCase {

  /**
   * Update requests sent to Square.
   *
   * @var \Square\Subscriptions\Requests\UpdateSubscriptionRequest[]
   */
  private array $updates = [];

  public function testAmountChangeIsSentToSquare(): void {
    $processor = $this->processor();
    $message = '';

    $result = $processor->changeSubscriptionAmount($message, $this->formParams(['amount' => '25.00']));

    $this->assertTrue($result);
    $this->assertCount(1, $this->updates);
    $this->assertSame('SUB-1', $this->updates[0]->getSubscriptionId());
    $this->assertSame(2500, $this->updates[0]->getSubscription()->getPriceOverrideMoney()->getAmount());
    $this->assertSame('USD', $this->updates[0]->getSubscription()->getPriceOverrideMoney()->getCurrency(), "The recurring contribution's currency.");
    $this->assertSame(3, $this->updates[0]->getSubscription()->getVersion());
  }

  /**
   * The form passes the amount as typed, thousands separator and all.
   */
  public function testFormattedAmountIsAccepted(): void {
    $message = '';

    $this->processor()->changeSubscriptionAmount($message, $this->formParams(['amount' => '1,250.50']));

    $this->assertSame(125050, $this->updates[0]->getSubscription()->getPriceOverrideMoney()->getAmount());
  }

  /**
   * CiviCRM would save new installments Square cannot apply.
   */
  public function testInstallmentChangeIsRefused(): void {
    $this->expectRefusal('number of installments');
    $message = '';
    $this->processor()->changeSubscriptionAmount($message, $this->formParams(['installments' => '6']));
  }

  /**
   * Later installments of a multi-item series could not follow the new amount.
   */
  public function testMultipleLineItemSeriesIsRefused(): void {
    $this->expectRefusal('more than one line item');
    $message = '';
    $this->processor(['line_item_count' => 2])->changeSubscriptionAmount($message, $this->formParams());
  }

  /**
   * Only the Square subscription linked to this recurring contribution is changed.
   *
   * @dataProvider unlinkedRecurProvider
   */
  public function testRecurNotLinkedToTheSubscriptionIsRefused(?array $recur): void {
    $this->expectRefusal('not linked to that Square subscription');
    $message = '';
    $this->processor($recur, $recur === NULL)->changeSubscriptionAmount($message, $this->formParams());
  }

  public static function unlinkedRecurProvider(): array {
    return [
      "another processor's recurring contribution" => [NULL],
      'a different subscription' => [['processor_id' => 'SUB-OTHER']],
    ];
  }

  /**
   * @dataProvider invalidAmountProvider
   */
  public function testInvalidAmountIsRefused($amount): void {
    $this->expectRefusal('positive amount');
    $message = '';
    $this->processor()->changeSubscriptionAmount($message, $this->formParams(['amount' => $amount]));
  }

  public static function invalidAmountProvider(): array {
    return [
      'zero' => ['0'],
      'negative' => ['-5'],
      'not a number' => ['abc'],
      'missing' => [NULL],
    ];
  }

  /**
   * A Square failure bounces the form rather than saving a change Square never made.
   */
  public function testSquareFailureIsReportedToTheForm(): void {
    $subscriptions = $this->createMock(SubscriptionsClient::class);
    $subscriptions->method('get')->willThrowException(new SquareException('connection timed out'));

    try {
      $message = '';
      $this->processor([], FALSE, $subscriptions)->changeSubscriptionAmount($message, $this->formParams());
      $this->fail('Expected a PaymentProcessorException.');
    }
    catch (PaymentProcessorException $e) {
      $this->assertStringContainsString('connection timed out', $e->getMessage());
      $this->assertInstanceOf(CRM_Core_Payment_SquareRetryableException::class, $e->getPrevious());
    }
  }

  /**
   * @param string $message
   */
  private function expectRefusal(string $message): void {
    $this->expectException(PaymentProcessorException::class);
    $this->expectExceptionMessage($message);
  }

  /**
   * What CRM_Contribute_Form_UpdateSubscription passes.
   *
   * @param array $overrides
   *
   * @return array
   */
  private function formParams(array $overrides = []): array {
    return array_replace([
      'id' => 45,
      'contributionRecurID' => 45,
      'subscriptionId' => 'SUB-1',
      'recurProcessorID' => 'SUB-1',
      'amount' => '19.00',
      'installments' => '',
    ], $overrides);
  }

  /**
   * A processor whose recurring contribution 45 is linked to SUB-1.
   *
   * @param array|null $recurOverrides
   * @param bool $noRecur
   *   Whether recurring contribution 45 belongs to another processor.
   * @param \Square\Subscriptions\SubscriptionsClient|null $subscriptions
   *
   * @return \CRM_Core_Payment_Square
   */
  private function processor(?array $recurOverrides = [], bool $noRecur = FALSE, ?SubscriptionsClient $subscriptions = NULL): CRM_Core_Payment_Square {
    if ($subscriptions === NULL) {
      $subscriptions = $this->createMock(SubscriptionsClient::class);
      $subscriptions->method('get')->willReturn(new GetSubscriptionResponse([
        'subscription' => new Subscription(['id' => 'SUB-1', 'version' => 3]),
      ]));
      $subscriptions->method('update')->willReturnCallback(function (UpdateSubscriptionRequest $request) {
        $this->updates[] = $request;
        return new UpdateSubscriptionResponse(['subscription' => $request->getSubscription()]);
      });
    }
    $client = new SquareClient(token: 'test-token', options: ['baseUrl' => 'https://example.invalid']);
    $client->subscriptions = $subscriptions;
    $recur = $noRecur ? NULL : array_replace([
      'processor_id' => 'SUB-1',
      'installments' => NULL,
      'currency' => 'USD',
      'line_item_count' => 1,
    ], $recurOverrides ?? []);
    $config = $this->processorConfig();

    return new class($config, $client, $recur) extends CRM_Core_Payment_Square {

      /**
       * @var \Square\SquareClient
       */
      private SquareClient $mockClient;

      /**
       * @var array|null
       */
      private ?array $recur;

      /**
       * @param array $paymentProcessor
       * @param \Square\SquareClient $mockClient
       * @param array|null $recur
       */
      public function __construct(array $paymentProcessor, SquareClient $mockClient, ?array $recur) {
        parent::__construct('live', $paymentProcessor);
        $this->mockClient = $mockClient;
        $this->recur = $recur;
      }

      /**
       * @return \Square\SquareClient
       */
      protected function buildSquareClient(): SquareClient {
        return $this->mockClient;
      }

      /**
       * @param int $recurId
       *
       * @return array|null
       */
      protected function getRecurForAmountChange(int $recurId): ?array {
        return $recurId === 45 ? $this->recur : NULL;
      }

    };
  }

}
