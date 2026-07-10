<?php

declare(strict_types=1);

namespace Pkg\SyliusEveryPayPlugin\CommandHandler;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Attribute\WithMonologChannel;
use Pkg\SyliusEveryPayPlugin\Client\EveryPayApiClient;
use Pkg\SyliusEveryPayPlugin\Client\EveryPayApiException;
use Pkg\SyliusEveryPayPlugin\Client\EveryPayCredentials;
use Pkg\SyliusEveryPayPlugin\Command\CaptureEveryPayPayment;
use Pkg\SyliusEveryPayPlugin\EveryPayGateway;
use Pkg\SyliusEveryPayPlugin\Factory\EveryPayOneOffPayloadFactory;
use Pkg\SyliusEveryPayPlugin\Provider\AfterPayUrlProviderInterface;
use Psr\Log\LoggerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\PaymentRequestTransitions;
use Sylius\Component\Payment\PaymentTransitions;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Creates the EveryPay one-off payment and stores the hosted payment page
 * link; EveryPayHttpResponseProvider then redirects the customer there.
 * Runs synchronously on the sylius.payment_request.command_bus during
 * GET /pay/{hash} right after checkout completion.
 */
#[AsMessageHandler]
#[WithMonologChannel('everypay')]
final readonly class CaptureEveryPayPaymentHandler
{
    public function __construct(
        private PaymentRequestProviderInterface $paymentRequestProvider,
        private EveryPayApiClient $apiClient,
        private EveryPayOneOffPayloadFactory $payloadFactory,
        private AfterPayUrlProviderInterface $afterPayUrlProvider,
        private StateMachineInterface $stateMachine,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(CaptureEveryPayPayment $command): void
    {
        $paymentRequest = $this->paymentRequestProvider->provide($command);

        // Serialize concurrent /pay/{hash} requests (double-click, browser
        // retry): FOR UPDATE blocks a competing capture until the winner's
        // transaction commits, and refresh re-reads the state it advanced -
        // otherwise both would create an EveryPay payment for the same
        // order_reference and the loser would wrongly fail this payment.
        // The active transaction comes from the bus' doctrine_transaction
        // middleware.
        $this->entityManager->refresh($paymentRequest, LockMode::PESSIMISTIC_WRITE);

        // Reloading /pay/{hash} re-dispatches capture - do not create a
        // second EveryPay payment for the same attempt.
        if (PaymentRequestInterface::STATE_PROCESSING === $paymentRequest->getState()) {
            return;
        }

        $payment = EveryPayGateway::corePaymentFrom($paymentRequest);

        $customerUrl = $this->afterPayUrlProvider->getUrl($paymentRequest);

        $credentials = EveryPayCredentials::fromPaymentMethod($paymentRequest->getMethod());

        // The processing account fixes the currency at EveryPay; a mismatch
        // is admin-entered data we trust, but make the coming rejection
        // diagnosable instead of a bare failed payment.
        $currencyHint = $credentials->currencyHint();
        if (null !== $currencyHint && $currencyHint !== $payment->getCurrencyCode()) {
            $this->logger->warning('EveryPay processing account suggests a different currency than the payment - EveryPay will likely reject it.', [
                'payment_id' => $payment->getId(),
                'account_name' => $credentials->accountName,
                'account_currency_hint' => $currencyHint,
                'payment_currency' => $payment->getCurrencyCode(),
            ]);
        }

        try {
            $response = $this->apiClient->createOneOffPayment(
                $credentials,
                $this->payloadFactory->create($payment, $customerUrl),
            );
        } catch (EveryPayApiException $e) {
            $this->logger->error('EveryPay one-off payment creation failed.', [
                'payment_id' => $payment->getId(),
                'exception' => $e,
            ]);
            $this->fail($paymentRequest, $payment, $e->getMessage());

            return;
        }

        $paymentReference = $response['payment_reference'] ?? null;
        $paymentLink = $response['payment_link'] ?? null;
        if (!is_string($paymentReference) || !is_string($paymentLink) || '' === $paymentReference || '' === $paymentLink) {
            $this->logger->error('EveryPay response is missing payment_reference or payment_link.', [
                'payment_id' => $payment->getId(),
                'response' => $response,
            ]);
            $this->fail($paymentRequest, $payment, 'EveryPay response is missing payment_reference or payment_link.');

            return;
        }

        $details = $payment->getDetails();
        $details[EveryPayGateway::DETAILS_KEY] = [
            'payment_reference' => $paymentReference,
            'payment_link' => $paymentLink,
            'payment_state' => $response['payment_state'] ?? null,
            'order_reference' => $response['order_reference'] ?? null,
            'account_name' => $response['account_name'] ?? null,
            'currency' => $response['currency'] ?? null,
            'created_at' => $response['payment_created_at'] ?? null,
        ];
        $payment->setDetails($details);

        $paymentRequest->setResponseData([
            'payment_link' => $paymentLink,
            'payment_reference' => $paymentReference,
            // Per-method direct links (bank buttons) for the optional
            // in-shop method grid; empty when EveryPay returns none.
            'payment_methods' => EveryPayGateway::paymentMethodOptionsFrom($response['payment_methods'] ?? null),
        ]);

        // The payment intentionally stays in `new`: PaymentToPayResolver only
        // re-enters the pay flow for `new` payments, so a customer who bounces
        // off the hosted page without confirming can come back and retry.
        // In-flight EveryPay states move it to `processing` via the synchronizer.
        $this->stateMachine->apply(
            $paymentRequest,
            PaymentRequestTransitions::GRAPH,
            PaymentRequestTransitions::TRANSITION_PROCESS,
        );
    }

    private function fail(PaymentRequestInterface $paymentRequest, PaymentInterface $payment, string $reason): void
    {
        $paymentRequest->setResponseData(['error' => $reason]);

        $this->stateMachine->apply(
            $paymentRequest,
            PaymentRequestTransitions::GRAPH,
            PaymentRequestTransitions::TRANSITION_FAIL,
        );

        if ($this->stateMachine->can($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_FAIL)) {
            $this->stateMachine->apply($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_FAIL);
        }
    }
}
