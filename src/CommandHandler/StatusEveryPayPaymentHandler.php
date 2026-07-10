<?php

declare(strict_types=1);

namespace Pkg\SyliusEveryPayPlugin\CommandHandler;

use Monolog\Attribute\WithMonologChannel;
use Pkg\SyliusEveryPayPlugin\Client\EveryPayApiException;
use Pkg\SyliusEveryPayPlugin\Command\StatusEveryPayPayment;
use Pkg\SyliusEveryPayPlugin\EveryPayGateway;
use Pkg\SyliusEveryPayPlugin\Processor\EveryPayPaymentSynchronizer;
use Psr\Log\LoggerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Component\Payment\PaymentRequestTransitions;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Runs when the customer returns from the EveryPay hosted page
 * (GET /after-pay/{hash} clones the capture request as a status request).
 * A temporary API failure is swallowed: the payment stays `processing` and
 * the server callback retries (up to 6 times over 72 h) settle it later.
 */
#[AsMessageHandler]
#[WithMonologChannel('everypay')]
final readonly class StatusEveryPayPaymentHandler
{
    public function __construct(
        private PaymentRequestProviderInterface $paymentRequestProvider,
        private EveryPayPaymentSynchronizer $paymentSynchronizer,
        private StateMachineInterface $stateMachine,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(StatusEveryPayPayment $command): void
    {
        $paymentRequest = $this->paymentRequestProvider->provide($command);

        $payment = EveryPayGateway::corePaymentFrom($paymentRequest);

        try {
            $this->paymentSynchronizer->synchronize($payment);
        } catch (EveryPayApiException $e) {
            $this->logger->error('EveryPay status check on customer return failed.', [
                'payment_id' => $payment->getId(),
                'exception' => $e,
            ]);
            $paymentRequest->setResponseData(['error' => $e->getMessage()]);
            $this->stateMachine->apply(
                $paymentRequest,
                PaymentRequestTransitions::GRAPH,
                PaymentRequestTransitions::TRANSITION_FAIL,
            );

            return;
        }

        $this->stateMachine->apply(
            $paymentRequest,
            PaymentRequestTransitions::GRAPH,
            PaymentRequestTransitions::TRANSITION_COMPLETE,
        );
    }
}
