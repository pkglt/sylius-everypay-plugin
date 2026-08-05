<?php

declare(strict_types=1);

namespace Tests\Pkg\SyliusEveryPayPlugin\Unit\CommandHandler;

use PHPUnit\Framework\TestCase;
use Pkg\SyliusEveryPayPlugin\Client\EveryPayApiClient;
use Pkg\SyliusEveryPayPlugin\Command\StatusEveryPayPayment;
use Pkg\SyliusEveryPayPlugin\CommandHandler\StatusEveryPayPaymentHandler;
use Pkg\SyliusEveryPayPlugin\EveryPayGateway;
use Pkg\SyliusEveryPayPlugin\Processor\EveryPayPaymentSynchronizer;
use Pkg\SyliusEveryPayPlugin\Processor\EveryPayStateMapper;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Model\PaymentRequest;
use Sylius\Component\Payment\PaymentRequestTransitions;
use Sylius\Component\Payment\PaymentTransitions;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Tests\Pkg\SyliusEveryPayPlugin\Support\RecordingLogger;

final class StatusEveryPayPaymentHandlerTest extends TestCase
{
    private const PAYMENT_REFERENCE = 'abc123def456abc123def456abc123def456abc123def456abc123def456abcd';

    /** Half of the HTTP Basic credential pair - it must never reach the shopper. */
    private const API_USERNAME = 'a04e7ce1060e7024';

    /** @var array<array{object, string, string}> */
    private array $appliedTransitions = [];

    public function testSettledReturnCompletesThePaymentAndTheRequest(): void
    {
        $paymentRequest = $this->paymentRequest();
        $handler = $this->handler($paymentRequest, new MockResponse(json_encode([
            'payment_state' => 'settled',
        ], \JSON_THROW_ON_ERROR)));

        $handler(new StatusEveryPayPayment('hash'));

        // The synchronizer moved the payment from the API truth, then the
        // handler completed the payment request itself.
        self::assertSame(
            [
                [$paymentRequest->getPayment(), PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_COMPLETE],
                [$paymentRequest, PaymentRequestTransitions::GRAPH, PaymentRequestTransitions::TRANSITION_COMPLETE],
            ],
            $this->appliedTransitions,
        );
    }

    public function testApiFailureFailsOnlyTheRequestSoCallbacksSettleThePaymentLater(): void
    {
        $paymentRequest = $this->paymentRequest();
        $handler = $this->handler($paymentRequest, new MockResponse('oops', ['http_code' => 500]));

        $handler(new StatusEveryPayPayment('hash'));

        // The documented invariant: a temporary API failure on customer return
        // is swallowed - the payment stays processing and the server callback
        // redeliveries settle it later.
        self::assertSame(
            ['error' => EveryPayGateway::ERROR_GATEWAY_UNAVAILABLE],
            $paymentRequest->getResponseData(),
        );
        self::assertSame(
            [[$paymentRequest, PaymentRequestTransitions::GRAPH, PaymentRequestTransitions::TRANSITION_FAIL]],
            $this->appliedTransitions,
        );

        $payment = $paymentRequest->getPayment();
        self::assertInstanceOf(Payment::class, $payment);
        self::assertSame(PaymentInterface::STATE_PROCESSING, $payment->getState());
    }

    public function testApiFailureKeepsTheCredentialAndGatewayTextOutOfTheShopperVisibleResponse(): void
    {
        $paymentRequest = $this->paymentRequest();
        $logger = new RecordingLogger();
        // The client folds the request path (which carries api_username) and the
        // raw gateway body into the exception message. responseData is served to
        // the shopper by the Sylius shop API, so neither may be persisted there.
        $handler = $this->handler(
            $paymentRequest,
            new MockResponse('gateway maintenance in progress', ['http_code' => 500]),
            $logger,
        );

        $handler(new StatusEveryPayPayment('hash'));

        $responseData = $paymentRequest->getResponseData();
        self::assertSame(['error' => EveryPayGateway::ERROR_GATEWAY_UNAVAILABLE], $responseData);

        $serialized = json_encode($responseData, \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString(self::API_USERNAME, $serialized);
        self::assertStringNotContainsString('maintenance', $serialized);

        // The detail stays operator-facing: it is logged (with the exception in
        // the context) on the everypay channel.
        self::assertSame(['EveryPay status check on customer return failed.'], $logger->messages('error'));
    }

    private function paymentRequest(): PaymentRequest
    {
        $gatewayConfig = $this->createStub(GatewayConfigInterface::class);
        $gatewayConfig->method('getConfig')->willReturn([
            EveryPayGateway::CONFIG_API_USERNAME => self::API_USERNAME,
            EveryPayGateway::CONFIG_API_SECRET => 'secret',
            EveryPayGateway::CONFIG_ACCOUNT_NAME => 'EUR3D1',
            EveryPayGateway::CONFIG_ENVIRONMENT => EveryPayGateway::ENVIRONMENT_DEMO,
        ]);

        $method = $this->createStub(PaymentMethodInterface::class);
        $method->method('getGatewayConfig')->willReturn($gatewayConfig);

        $payment = new Payment();
        $payment->setState(PaymentInterface::STATE_PROCESSING);
        $payment->setMethod($method);
        $payment->setDetails([
            EveryPayGateway::DETAILS_KEY => ['payment_reference' => self::PAYMENT_REFERENCE],
        ]);

        return new PaymentRequest($payment, $method);
    }

    private function handler(PaymentRequest $paymentRequest, MockResponse $apiResponse, ?LoggerInterface $logger = null): StatusEveryPayPaymentHandler
    {
        $paymentRequestProvider = $this->createStub(PaymentRequestProviderInterface::class);
        $paymentRequestProvider->method('provide')->willReturn($paymentRequest);

        $this->appliedTransitions = [];
        $stateMachine = $this->createStub(StateMachineInterface::class);
        $stateMachine->method('getTransitionToState')->willReturn(PaymentTransitions::TRANSITION_COMPLETE);
        $stateMachine->method('apply')->willReturnCallback(
            function (object $subject, string $graph, string $transition): void {
                $this->appliedTransitions[] = [$subject, $graph, $transition];
            },
        );

        $synchronizer = new EveryPayPaymentSynchronizer(
            new EveryPayApiClient(new MockHttpClient($apiResponse)),
            new EveryPayStateMapper(),
            $stateMachine,
            new NullLogger(),
        );

        return new StatusEveryPayPaymentHandler(
            $paymentRequestProvider,
            $synchronizer,
            $stateMachine,
            $logger ?? new NullLogger(),
        );
    }
}
