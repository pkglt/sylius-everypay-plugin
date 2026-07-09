<?php

declare(strict_types=1);

namespace Pkg\SyliusEveryPayPlugin\Processor;

use Pkg\SyliusEveryPayPlugin\Client\EveryPayApiClient;
use Pkg\SyliusEveryPayPlugin\Client\EveryPayCredentials;
use Pkg\SyliusEveryPayPlugin\EveryPayGateway;
use Psr\Log\LoggerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\PaymentTransitions;

/**
 * Fetches the authoritative payment state from the EveryPay API and moves the
 * Sylius payment through its state machine accordingly. Used by both the
 * customer-return (status) and the server callback (notify) handlers - the
 * two arrive in any order and any number of times, so this is idempotent:
 * when the payment is already in the target state nothing happens.
 */
final class EveryPayPaymentSynchronizer
{
    /**
     * Workflow context marker set on every transition this synchronizer
     * applies. RefundEveryPayPaymentListener skips events carrying it - a
     * refund initiated in the EveryPay portal arrives here as a `refunded`
     * callback and must not trigger a second refund API call.
     */
    public const WORKFLOW_CONTEXT = 'pkg_everypay_sync';

    public function __construct(
        private readonly EveryPayApiClient $apiClient,
        private readonly EveryPayStateMapper $stateMapper,
        private readonly StateMachineInterface $stateMachine,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function synchronize(PaymentInterface $payment): void
    {
        $details = $payment->getDetails();
        $paymentReference = EveryPayGateway::paymentReferenceFrom($details);

        if (null === $paymentReference) {
            // Capture never reached EveryPay (API error before redirect) -
            // fail the payment so Sylius offers the customer a new attempt.
            $this->applyTargetState($payment, PaymentInterface::STATE_FAILED);

            return;
        }

        $remote = $this->apiClient->getPayment(
            EveryPayCredentials::fromPaymentMethod($payment->getMethod()),
            $paymentReference,
        );

        $details[EveryPayGateway::DETAILS_KEY] = array_merge(
            EveryPayGateway::detailsFrom($details),
            [
                'payment_state' => $remote['payment_state'] ?? null,
                'payment_method' => $remote['payment_method'] ?? null,
                'standing_amount' => $remote['standing_amount'] ?? null,
                'synchronized_at' => $remote['payment_created_at'] ?? null,
            ],
        );
        $payment->setDetails($details);

        $remoteState = $remote['payment_state'] ?? null;
        $targetState = is_string($remoteState) ? $this->stateMapper->toSyliusState($remoteState) : null;
        if (null !== $targetState) {
            $this->applyTargetState($payment, $targetState);
        } elseif ('charged_back' === $remoteState) {
            $this->logger->warning('EveryPay payment was charged back - handle manually in the merchant portal.', [
                'payment_id' => $payment->getId(),
                'payment_reference' => $paymentReference,
            ]);
        }
    }

    private function applyTargetState(PaymentInterface $payment, string $targetState): void
    {
        if ($payment->getState() === $targetState) {
            return;
        }

        $transition = $this->stateMachine->getTransitionToState($payment, PaymentTransitions::GRAPH, $targetState);
        if (null === $transition) {
            // E.g. a payment stuck in failed/cancelled while EveryPay reports
            // settled/refunded - money moved but Sylius cannot follow. Make it
            // loud so an operator reconciles manually instead of it vanishing.
            $this->logger->warning('EveryPay reports a state Sylius cannot transition to - reconcile manually.', [
                'payment_id' => $payment->getId(),
                'current_state' => $payment->getState(),
                'target_state' => $targetState,
            ]);

            return;
        }

        $this->stateMachine->apply($payment, PaymentTransitions::GRAPH, $transition, [self::WORKFLOW_CONTEXT => true]);
    }
}
