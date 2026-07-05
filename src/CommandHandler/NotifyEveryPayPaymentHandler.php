<?php

declare(strict_types=1);

namespace Pkg\SyliusEveryPayPlugin\CommandHandler;

use Pkg\SyliusEveryPayPlugin\Command\NotifyEveryPayPayment;
use Pkg\SyliusEveryPayPlugin\EveryPayGateway;
use Pkg\SyliusEveryPayPlugin\Processor\EveryPayPaymentSynchronizer;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Component\Payment\PaymentRequestTransitions;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Runs for EveryPay server-to-server callbacks
 * (POST/GET /payment-methods/{code}?payment_reference=…&event_name=…).
 * Callbacks are unauthenticated, so the synchronizer re-reads the state from
 * the EveryPay API instead of trusting the request. An API failure is left
 * to bubble up: the resulting non-2xx response makes EveryPay redeliver the
 * callback later.
 */
#[AsMessageHandler]
final readonly class NotifyEveryPayPaymentHandler
{
    public function __construct(
        private PaymentRequestProviderInterface $paymentRequestProvider,
        private EveryPayPaymentSynchronizer $paymentSynchronizer,
        private StateMachineInterface $stateMachine,
    ) {
    }

    public function __invoke(NotifyEveryPayPayment $command): void
    {
        $paymentRequest = $this->paymentRequestProvider->provide($command);

        $payment = EveryPayGateway::corePaymentFrom($paymentRequest);

        $this->paymentSynchronizer->synchronize($payment);

        $this->stateMachine->apply(
            $paymentRequest,
            PaymentRequestTransitions::GRAPH,
            PaymentRequestTransitions::TRANSITION_COMPLETE,
        );
    }
}
