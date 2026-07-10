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

final class StatusEveryPayPaymentHandlerTest extends TestCase
{
    private const PAYMENT_REFERENCE = 'abc123def456abc123def456abc123def456abc123def456abc123def456abcd';

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
        self::assertArrayHasKey('error', $paymentRequest->getResponseData());
        self::assertSame(
            [[$paymentRequest, PaymentRequestTransitions::GRAPH, PaymentRequestTransitions::TRANSITION_FAIL]],
            $this->appliedTransitions,
        );

        $payment = $paymentRequest->getPayment();
        self::assertInstanceOf(Payment::class, $payment);
        self::assertSame(PaymentInterface::STATE_PROCESSING, $payment->getState());
    }

    private function paymentRequest(): PaymentRequest
    {
        $gatewayConfig = $this->createStub(GatewayConfigInterface::class);
        $gatewayConfig->method('getConfig')->willReturn([
            EveryPayGateway::CONFIG_API_USERNAME => 'a04e7ce1060e7024',
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

    private function handler(PaymentRequest $paymentRequest, MockResponse $apiResponse): StatusEveryPayPaymentHandler
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
            new NullLogger(),
        );
    }
}
