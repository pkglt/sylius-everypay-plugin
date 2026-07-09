<?php

declare(strict_types=1);

namespace Pkg\SyliusEveryPayPlugin\CommandHandler;

use Pkg\SyliusEveryPayPlugin\Client\EveryPayApiClient;
use Pkg\SyliusEveryPayPlugin\Client\EveryPayCredentials;
use Pkg\SyliusEveryPayPlugin\Command\RefundEveryPayPayment;
use Pkg\SyliusEveryPayPlugin\EveryPayGateway;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Component\Payment\PaymentRequestTransitions;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Executes the actual EveryPay refund (full payment amount) after the admin
 * pressed the core Refund button. Failures deliberately propagate:
 * RefundEveryPayPaymentListener runs this inside a transaction and rolls the
 * whole refund back (state + payment request) when the API call fails.
 * The payment state itself is not touched here - the admin's refund
 * transition already moved it.
 */
#[AsMessageHandler]
final readonly class RefundEveryPayPaymentHandler
{
    public function __construct(
        private PaymentRequestProviderInterface $paymentRequestProvider,
        private EveryPayApiClient $apiClient,
        private StateMachineInterface $stateMachine,
    ) {
    }

    public function __invoke(RefundEveryPayPayment $command): void
    {
        $paymentRequest = $this->paymentRequestProvider->provide($command);

        $payment = EveryPayGateway::corePaymentFrom($paymentRequest);

        $details = $payment->getDetails();
        $paymentReference = EveryPayGateway::paymentReferenceFrom($details);
        if (null === $paymentReference) {
            throw new \LogicException(sprintf('Payment #%d has no EveryPay payment reference to refund.', (int) $payment->getId()));
        }

        $response = $this->apiClient->refundPayment(
            EveryPayCredentials::fromPaymentMethod($payment->getMethod()),
            $paymentReference,
            round(((int) $payment->getAmount()) / 100, 2),
        );

        $details[EveryPayGateway::DETAILS_KEY] = array_merge(EveryPayGateway::detailsFrom($details), [
            'payment_state' => $response['payment_state'] ?? 'refunded',
        ]);
        $payment->setDetails($details);

        $paymentRequest->setResponseData(['payment_state' => $response['payment_state'] ?? null]);

        $this->stateMachine->apply(
            $paymentRequest,
            PaymentRequestTransitions::GRAPH,
            PaymentRequestTransitions::TRANSITION_COMPLETE,
        );
    }
}
