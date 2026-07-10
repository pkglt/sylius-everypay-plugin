<?php

declare(strict_types=1);

namespace Tests\Pkg\SyliusEveryPayPlugin\Unit\Processor;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Pkg\SyliusEveryPayPlugin\Client\EveryPayApiClient;
use Pkg\SyliusEveryPayPlugin\EveryPayGateway;
use Pkg\SyliusEveryPayPlugin\Processor\EveryPayPaymentSynchronizer;
use Pkg\SyliusEveryPayPlugin\Processor\EveryPayStateMapper;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Sylius\Component\Payment\PaymentTransitions;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Tests\Pkg\SyliusEveryPayPlugin\Support\RecordingLogger;

final class EveryPayPaymentSynchronizerTest extends TestCase
{
    private const PAYMENT_REFERENCE = 'abc123def456abc123def456abc123def456abc123def456abc123def456abcd';

    /** @var MockObject&StateMachineInterface */
    private StateMachineInterface $stateMachine;

    protected function setUp(): void
    {
        $this->stateMachine = $this->createMock(StateMachineInterface::class);
    }

    public function testSettledRemoteStateCompletesThePayment(): void
    {
        $payment = $this->everyPayPayment(PaymentInterface::STATE_PROCESSING);
        $synchronizer = $this->synchronizer(['payment_state' => 'settled', 'standing_amount' => 25.99]);

        $this->stateMachine
            ->expects(self::once())
            ->method('getTransitionToState')
            ->with($payment, PaymentTransitions::GRAPH, PaymentInterface::STATE_COMPLETED)
            ->willReturn(PaymentTransitions::TRANSITION_COMPLETE);
        $this->stateMachine
            ->expects(self::once())
            ->method('apply')
            ->with(
                $payment,
                PaymentTransitions::GRAPH,
                PaymentTransitions::TRANSITION_COMPLETE,
                [EveryPayPaymentSynchronizer::WORKFLOW_CONTEXT => true],
            );

        $synchronizer->synchronize($payment);

        self::assertSame('settled', EveryPayGateway::detailsFrom($payment->getDetails())['payment_state']);
    }

    public function testAlreadyCompletedPaymentIsLeftAlone(): void
    {
        $payment = $this->everyPayPayment(PaymentInterface::STATE_COMPLETED);
        $synchronizer = $this->synchronizer(['payment_state' => 'settled']);

        $this->stateMachine->expects(self::never())->method('getTransitionToState');
        $this->stateMachine->expects(self::never())->method('apply');

        $synchronizer->synchronize($payment);
    }

    public function testUnreachableTargetStateIsNeverAppliedSilently(): void
    {
        // failed -> completed has no transition in the sylius_payment graph;
        // the synchronizer must not apply anything and must warn loudly so an
        // operator reconciles the moved money by hand.
        $payment = $this->everyPayPayment(PaymentInterface::STATE_FAILED);
        $logger = new RecordingLogger();
        $synchronizer = $this->synchronizer(['payment_state' => 'settled'], $logger);

        $this->stateMachine
            ->expects(self::once())
            ->method('getTransitionToState')
            ->with($payment, PaymentTransitions::GRAPH, PaymentInterface::STATE_COMPLETED)
            ->willReturn(null);
        $this->stateMachine->expects(self::never())->method('apply');

        $synchronizer->synchronize($payment);

        $warnings = $logger->messages('warning');
        self::assertCount(1, $warnings);
        self::assertStringContainsString('reconcile manually', $warnings[0]);
    }

    public function testChargedBackIsLeftAloneWithALoudWarning(): void
    {
        $payment = $this->everyPayPayment(PaymentInterface::STATE_COMPLETED);
        $logger = new RecordingLogger();
        $synchronizer = $this->synchronizer(['payment_state' => 'charged_back'], $logger);

        $this->stateMachine->expects(self::never())->method('getTransitionToState');
        $this->stateMachine->expects(self::never())->method('apply');

        $synchronizer->synchronize($payment);

        // Deliberately unmapped: chargebacks are handled in the merchant
        // portal, but the operator must hear about them.
        $warnings = $logger->messages('warning');
        self::assertCount(1, $warnings);
        self::assertStringContainsString('charged back', $warnings[0]);
        self::assertSame('charged_back', EveryPayGateway::detailsFrom($payment->getDetails())['payment_state']);
    }

    public function testMissingPaymentReferenceFailsThePaymentWithoutApiCall(): void
    {
        $payment = new Payment();
        $payment->setState(PaymentInterface::STATE_PROCESSING);

        $httpClient = new MockHttpClient(function (): MockResponse {
            self::fail('No API call expected when the payment has no EveryPay reference.');
        });
        $synchronizer = new EveryPayPaymentSynchronizer(
            new EveryPayApiClient($httpClient),
            new EveryPayStateMapper(),
            $this->stateMachine,
            new NullLogger(),
        );

        $this->stateMachine
            ->expects(self::once())
            ->method('getTransitionToState')
            ->with($payment, PaymentTransitions::GRAPH, PaymentInterface::STATE_FAILED)
            ->willReturn(PaymentTransitions::TRANSITION_FAIL);
        $this->stateMachine
            ->expects(self::once())
            ->method('apply')
            ->with(
                $payment,
                PaymentTransitions::GRAPH,
                PaymentTransitions::TRANSITION_FAIL,
                [EveryPayPaymentSynchronizer::WORKFLOW_CONTEXT => true],
            );

        $synchronizer->synchronize($payment);
    }

    /**
     * @param array<string, mixed> $remotePayment
     */
    private function synchronizer(array $remotePayment, ?LoggerInterface $logger = null): EveryPayPaymentSynchronizer
    {
        $httpClient = new MockHttpClient(new MockResponse(json_encode($remotePayment, \JSON_THROW_ON_ERROR)));

        return new EveryPayPaymentSynchronizer(
            new EveryPayApiClient($httpClient),
            new EveryPayStateMapper(),
            $this->stateMachine,
            $logger ?? new NullLogger(),
        );
    }

    private function everyPayPayment(string $state): Payment
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
        $payment->setState($state);
        $payment->setMethod($method);
        $payment->setDetails([
            EveryPayGateway::DETAILS_KEY => ['payment_reference' => self::PAYMENT_REFERENCE],
        ]);

        return $payment;
    }
}
