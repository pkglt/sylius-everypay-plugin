<?php

declare(strict_types=1);

namespace Tests\Pkg\SyliusEveryPayPlugin\Behat\Context;

use Behat\Behat\Context\Context;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use Doctrine\ORM\EntityManagerInterface;
use Pkg\SyliusEveryPayPlugin\EveryPayGateway;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Model\PaymentInterface as BasePaymentInterface;
use Sylius\Component\Payment\PaymentTransitions;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Tests\Pkg\SyliusEveryPayPlugin\Behat\SharedStorage;
use Tests\Pkg\SyliusEveryPayPlugin\Functional\Support\EveryPayHttpMock;
use Tests\Pkg\SyliusEveryPayPlugin\Support\ShopFixtures;
use Webmozart\Assert\Assert;

/**
 * Refund flows: the admin-initiated refund (the sylius_payment refund
 * transition — exactly what the admin Refund button applies) and the
 * portal-initiated refund arriving as a callback, which must never trigger
 * a second refund API call.
 */
final class EveryPayRefundContext implements Context
{
    private const PAYMENT_REFERENCE = 'abc123def456abc123def456abc123def456abc123def456abc123def456abcd';

    private ?PaymentInterface $payment = null;

    private ?\Throwable $refundFailure = null;

    public function __construct(
        private readonly EveryPayHttpMock $everyPayApi,
        private readonly ShopFixtures $shopFixtures,
        private readonly SharedStorage $sharedStorage,
        private readonly EntityManagerInterface $entityManager,
        private readonly StateMachineInterface $stateMachine,
    ) {
    }

    #[Given('there is a completed EveryPay payment')]
    public function thereIsACompletedEveryPayPayment(): void
    {
        $this->payment = $this->shopFixtures->createOrderWithPayment(
            $this->sharedStorage->get('channel', ChannelInterface::class),
            $this->sharedStorage->get('payment_method', PaymentMethodInterface::class),
            [
                EveryPayGateway::DETAILS_KEY => [
                    'payment_reference' => self::PAYMENT_REFERENCE,
                    'payment_state' => 'settled',
                ],
            ],
            amount: 2599,
            paymentState: BasePaymentInterface::STATE_COMPLETED,
        );
        $this->sharedStorage->set('payment', $this->payment);
    }

    #[Given('EveryPay will confirm the refund')]
    public function everyPayWillConfirmTheRefund(): void
    {
        $this->everyPayApi->queueJson([
            'payment_reference' => self::PAYMENT_REFERENCE,
            'payment_state' => 'refunded',
            'standing_amount' => 0,
        ]);
    }

    #[Given('EveryPay will fail the refund')]
    public function everyPayWillFailTheRefund(): void
    {
        $this->everyPayApi->queueJson([
            'error' => ['code' => 5000, 'message' => 'Processing error'],
        ], 500);
    }

    #[Given('EveryPay reports the payment as refunded')]
    public function everyPayReportsThePaymentAsRefunded(): void
    {
        $this->everyPayApi->queueJson([
            'payment_reference' => self::PAYMENT_REFERENCE,
            'payment_state' => 'refunded',
            'payment_method' => 'card',
            'standing_amount' => 0,
        ]);
    }

    #[When('the administrator refunds the payment')]
    public function theAdministratorRefundsThePayment(): void
    {
        $this->stateMachine->apply($this->requiredPayment(), PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_REFUND);
        $this->entityManager->flush();
    }

    #[When('the administrator tries to refund the payment')]
    public function theAdministratorTriesToRefundThePayment(): void
    {
        try {
            $this->theAdministratorRefundsThePayment();
        } catch (\Throwable $failure) {
            $this->refundFailure = $failure;
        }
    }

    #[When('EveryPay delivers the refunded callback')]
    public function everyPayDeliversTheRefundedCallback(): void
    {
        $this->client()->request(
            'POST',
            sprintf('/payment-methods/everypay?payment_reference=%s&event_name=refunded', self::PAYMENT_REFERENCE),
        );
    }

    #[Then('the payment is refunded')]
    public function thePaymentIsRefunded(): void
    {
        Assert::same($this->freshPayment()->getState(), BasePaymentInterface::STATE_REFUNDED);
    }

    #[Then('the refund is rejected')]
    public function theRefundIsRejected(): void
    {
        Assert::notNull($this->refundFailure, 'The refund unexpectedly succeeded.');
    }

    #[Then('the payment remains completed in the database')]
    public function thePaymentRemainsCompletedInTheDatabase(): void
    {
        Assert::same($this->freshPayment()->getState(), BasePaymentInterface::STATE_COMPLETED);
    }

    #[Then('a single refund request was sent to EveryPay')]
    public function aSingleRefundRequestWasSentToEveryPay(): void
    {
        Assert::count($this->recordedRefundRequests(), 1);
    }

    #[Then('no refund request was sent to EveryPay')]
    public function noRefundRequestWasSentToEveryPay(): void
    {
        Assert::count($this->recordedRefundRequests(), 0);
    }

    /**
     * @return list<array{method: string, url: string, body: ?string}>
     */
    private function recordedRefundRequests(): array
    {
        return array_values(array_filter(
            $this->everyPayApi->recordedRequests(),
            static fn (array $request): bool => str_contains($request['url'], '/v4/payments/refund'),
        ));
    }

    private function requiredPayment(): PaymentInterface
    {
        Assert::notNull($this->payment);

        return $this->payment;
    }

    /**
     * Reads the payment state as persisted — after a rolled-back refund the
     * in-memory entity may carry a state that never reached the database.
     */
    private function freshPayment(): PaymentInterface
    {
        $id = $this->requiredPayment()->getId();
        $this->entityManager->clear();
        $payment = $this->entityManager->find($this->requiredPayment()::class, $id);
        Assert::isInstanceOf($payment, PaymentInterface::class);
        $this->payment = $payment;

        return $payment;
    }

    private function client(): KernelBrowser
    {
        return $this->sharedStorage->get('client', KernelBrowser::class);
    }
}
