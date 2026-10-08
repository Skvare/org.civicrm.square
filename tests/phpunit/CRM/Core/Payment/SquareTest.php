<?php

use Civi\Payment\Exception\PaymentProcessorException;
use Square\Types\Money;
use Square\Types\PaymentRefund;
use Square\Types\RefundPaymentResponse;
use Square\Refunds\Requests\RefundPaymentRequest;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 5) . '/CRM/Core/Payment/Square.php';

/**
 * Unit coverage for payment-processor behavior.
 *
 * Must not depend on a CiviCRM database or live Square credentials.
 */
class CRM_Core_Payment_SquareTest extends TestCase {

  private function processor(array $overrides = []): CRM_Core_Payment_Square {
    $processor = array_replace([
      'id' => 42,
      'is_test' => FALSE,
      'user_name' => 'application-id',
      'password' => 'access-token',
      'signature' => 'location-id',
      'subject' => 'signature-key',
    ], $overrides);
    return new CRM_Core_Payment_Square('live', $processor);
  }

  public function testCheckConfigReportsEveryMissingCredential(): void {
    $processor = $this->processor([
      'user_name' => '',
      'password' => '',
      'signature' => '',
      'subject' => '',
    ]);

    $error = $processor->checkConfig();

    $this->assertStringContainsString('Application ID', $error);
    $this->assertStringContainsString('Access Token', $error);
    $this->assertStringContainsString('Location ID', $error);
    $this->assertStringContainsString('Webhook Signature Key', $error);
  }

  public function testCheckConfigAcceptsCompleteCredentials(): void {
    $this->assertNull($this->processor()->checkConfig());
  }

  public function testIdempotencyKeyIsStableAndScoped(): void {
    $liveConfig = $this->processorConfig();
    $processor = new class('live', $liveConfig) extends CRM_Core_Payment_Square {

      public function key(string $operation, string $reference): string {
        return $this->idempotencyKey($operation, $reference);
      }

    };

    $same = $processor->key('payment', 'invoice-123');
    $this->assertSame($same, $processor->key('payment', 'invoice-123'));
    $this->assertNotSame($same, $processor->key('refund', 'invoice-123'));

    $sandboxConfig = $this->processorConfig(['is_test' => TRUE]);
    $sandbox = new class('test', $sandboxConfig) extends CRM_Core_Payment_Square {

      public function key(string $operation, string $reference): string {
        return $this->idempotencyKey($operation, $reference);
      }

    };
    $this->assertNotSame($same, $sandbox->key('payment', 'invoice-123'));
  }

  /**
   * @dataProvider completedRefundStatusesProvider
   */
  public function testRefundReturnsCiviRefundStatus(array $squareStatuses): void {
    $processor = $this->refundingProcessor(...$squareStatuses);
    $params = ['trxn_id' => 'payment-1', 'amount' => '12.34', 'currency' => 'USD'];

    $result = $processor->doRefund($params);

    // mjwshared's refund form records the refund only for 'Completed'.
    $this->assertSame('Completed', $result['refund_status']);
    $this->assertSame('refund-1', $result['refund_trxn_id']);
    $this->assertSame(0, $result['fee_amount']);
    $this->assertSame('2026-09-30 12:00:00', date('Y-m-d H:i:s', strtotime($result['trxn_date'])));
  }

  public static function completedRefundStatusesProvider(): array {
    return [
      'completed' => [['COMPLETED']],
      'pending, as Square first reports a card refund, then completed' => [['PENDING', 'PENDING', 'COMPLETED']],
    ];
  }

  public function testRefundStillPendingIsNotRecorded(): void {
    $processor = $this->refundingProcessor('PENDING');
    $params = ['trxn_id' => 'payment-1', 'amount' => '12.34'];

    try {
      $processor->doRefund($params);
      $this->fail('A refund Square has not completed must not be reported Completed.');
    }
    catch (PaymentProcessorException $e) {
      $this->assertStringContainsString('refund-1 but has not completed it yet', $e->getMessage());
    }
    // It was checked on, a few times, before giving up.
    $this->assertSame(5, $processor->pauses);
  }

  public function testRefundThatFailsWhilePendingIsAPaymentFailure(): void {
    $processor = $this->refundingProcessor('PENDING', 'FAILED');
    $params = ['trxn_id' => 'payment-1', 'amount' => '12.34'];

    $this->expectException(PaymentProcessorException::class);
    $this->expectExceptionMessage('Status: FAILED');
    $processor->doRefund($params);
  }

  public function testRefundWhoseStatusCannotBeCheckedIsReportedPending(): void {
    $processor = $this->refundingProcessor('PENDING');
    $processor->refundCheckFails = TRUE;
    $params = ['trxn_id' => 'payment-1', 'amount' => '12.34'];

    $this->expectException(PaymentProcessorException::class);
    $this->expectExceptionMessage('has not completed it yet');
    $processor->doRefund($params);
  }

  /**
   * @dataProvider refusedRefundStatusProvider
   */
  public function testRefundSquareDidNotAcceptIsAPaymentFailure(string $squareStatus): void {
    $processor = $this->refundingProcessor($squareStatus);
    $params = ['trxn_id' => 'payment-1', 'amount' => '12.34'];

    $this->expectException(PaymentProcessorException::class);
    $this->expectExceptionMessage("Status: {$squareStatus}");
    $processor->doRefund($params);
  }

  public static function refusedRefundStatusProvider(): array {
    return [['REJECTED'], ['FAILED']];
  }

  public function testBuildFormNeverPublishesTheAccessToken(): void {
    $processor = $this->processor(['signature' => NULL]);
    $form = new class() {

      /**
       * @var array
       */
      public array $assigned = [];

      public function elementExists($name) {
        return TRUE;
      }

      public function assign($name, $value) {
        $this->assigned[$name] = $value;
      }

    };

    $processor->buildForm($form);

    $this->assertStringNotContainsString('access-token', $form->assigned['squareJSVarsJson']);
    $this->assertSame('', json_decode($form->assigned['squareJSVarsJson'], TRUE)['locationId']);
  }

  /**
   * A processor whose Square refund comes back with the given statuses.
   *
   * @param string ...$squareStatuses
   *   As the refund is created, then as each later check finds it (the last
   *   one repeating).
   */
  private function refundingProcessor(string ...$squareStatuses): CRM_Core_Payment_Square {
    $config = $this->processorConfig();
    return new class('live', $config, $squareStatuses) extends CRM_Core_Payment_Square {

      /**
       * @var string[]
       */
      private array $squareStatuses;

      /**
       * Number of pauses between checks on the refund.
       *
       * @var int
       */
      public int $pauses = 0;

      /**
       * Whether checking on the refund fails.
       *
       * @var bool
       */
      public bool $refundCheckFails = FALSE;

      public function __construct($mode, array &$paymentProcessor, array $squareStatuses) {
        parent::__construct($mode, $paymentProcessor);
        $this->squareStatuses = $squareStatuses;
      }

      protected function getRefundContext(string $paymentTrxnId): array {
        return ['USD', 0];
      }

      protected function createRefund(RefundPaymentRequest $request) {
        return new RefundPaymentResponse(['refund' => $this->refund()]);
      }

      protected function getRefund(string $refundId): ?PaymentRefund {
        if ($this->refundCheckFails) {
          throw new CRM_Core_Payment_SquareRetryableException('Square API request failed: timed out');
        }
        if (count($this->squareStatuses) > 1) {
          array_shift($this->squareStatuses);
        }
        return $this->refund();
      }

      protected function pause(int $microseconds): void {
        $this->pauses++;
      }

      private function refund(): PaymentRefund {
        return new PaymentRefund([
          'id' => 'refund-1',
          'locationId' => 'location-1',
          'status' => $this->squareStatuses[0],
          'amountMoney' => new Money(['amount' => 1234, 'currency' => 'USD']),
          'createdAt' => '2026-09-30T12:00:00Z',
        ]);
      }

    };
  }

  private function processorConfig(array $overrides = []): array {
    return array_replace([
      'id' => 42,
      'is_test' => FALSE,
      'user_name' => 'application-id',
      'password' => 'access-token',
      'signature' => 'location-id',
      'subject' => 'signature-key',
    ], $overrides);
  }

}
