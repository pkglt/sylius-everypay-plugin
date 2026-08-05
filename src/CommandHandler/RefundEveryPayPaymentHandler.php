<?php

declare(strict_types=1);

namespace Pkg\SyliusEveryPayPlugin\CommandHandler;

use Monolog\Attribute\WithMonologChannel;
use Pkg\SyliusEveryPayPlugin\Client\EveryPayApiClient;
use Pkg\SyliusEveryPayPlugin\Client\EveryPayApiException;
use Pkg\SyliusEveryPayPlugin\Client\EveryPayCredentials;
use Pkg\SyliusEveryPayPlugin\Command\RefundEveryPayPayment;
use Pkg\SyliusEveryPayPlugin\EveryPayGateway;
use Pkg\SyliusEveryPayPlugin\Processor\EveryPayStateMapper;
use Psr\Log\LoggerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\PaymentRequestTransitions;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Executes the actual EveryPay refund (full payment amount) after the admin
 * pressed the core Refund button. Failures deliberately propagate:
 * RefundEveryPayPaymentListener runs this inside a transaction and rolls the
 * whole refund back (state + payment request) when the API call fails.
 * The payment state itself is not touched here - the admin's refund
 * transition already moved it.
 *
 * One rejection is recoverable: EveryPay refuses a refund whose money already
 * left the account - typically a refund made in the merchant portal whose
 * callback has not been processed yet. The error response is never trusted;
 * the authoritative payment state is re-read, and only a confirmed `refunded`
 * lets the admin's transition complete instead of failing.
 */
#[AsMessageHandler]
#[WithMonologChannel('everypay')]
final readonly class RefundEveryPayPaymentHandler
{
    public function __construct(
        private PaymentRequestProviderInterface $paymentRequestProvider,
        private EveryPayApiClient $apiClient,
        private StateMachineInterface $stateMachine,
        private LoggerInterface $logger,
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

        $credentials = EveryPayCredentials::fromPaymentMethod($payment->getMethod());

        try {
            $response = $this->apiClient->refundPayment(
                $credentials,
                $paymentReference,
                EveryPayGateway::amountToDecimal((int) $payment->getAmount()),
            );
            $response['payment_state'] ??= EveryPayStateMapper::STATE_REFUNDED;
        } catch (EveryPayApiException $rejection) {
            $response = $this->reconcileRejectedRefund($rejection, $credentials, $paymentReference, $payment);
        }

        $payment->setDetails(EveryPayGateway::withRemoteSnapshot($details, $response));

        $paymentRequest->setResponseData(['payment_state' => $response['payment_state'] ?? null]);

        $this->stateMachine->apply(
            $paymentRequest,
            PaymentRequestTransitions::GRAPH,
            PaymentRequestTransitions::TRANSITION_COMPLETE,
        );
    }

    /**
     * A 4xx rejection can mean the money already left the account (the refund
     * amount exceeds what is standing) - the rejection body itself proves
     * nothing, so the authoritative state is re-read and only a confirmed
     * `refunded` reconciles. Note that EveryPay reports `refunded` for partial
     * portal refunds too - the snapshot's standing_amount keeps the actual
     * remainder visible on the admin order page.
     *
     * @return array<string, mixed> the authoritative payment payload confirming the refund
     */
    private function reconcileRejectedRefund(
        EveryPayApiException $rejection,
        EveryPayCredentials $credentials,
        string $paymentReference,
        PaymentInterface $payment,
    ): array {
        if ($rejection->statusCode < 400 || $rejection->statusCode >= 500) {
            throw $rejection;
        }

        try {
            $remote = $this->apiClient->getPayment($credentials, $paymentReference);
        } catch (EveryPayApiException) {
            throw $rejection;
        }

        if (EveryPayStateMapper::STATE_REFUNDED !== ($remote['payment_state'] ?? null)) {
            throw $rejection;
        }

        $this->logger->info('EveryPay rejected the refund but reports the payment as refunded - reconciling.', [
            'payment_id' => $payment->getId(),
            'payment_reference' => $paymentReference,
        ]);

        return $remote;
    }
}
