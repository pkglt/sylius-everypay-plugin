<?php

declare(strict_types=1);

namespace Pkg\SyliusEveryPayPlugin\EventListener;

use Doctrine\ORM\EntityManagerInterface;
use Pkg\SyliusEveryPayPlugin\EveryPayGateway;
use Pkg\SyliusEveryPayPlugin\Processor\EveryPayPaymentSynchronizer;
use Psr\Log\LoggerInterface;
use Sylius\Bundle\PaymentBundle\Announcer\PaymentRequestAnnouncerInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\Factory\PaymentRequestFactoryInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\Repository\PaymentRequestRepositoryInterface;
use Sylius\Resource\Exception\UpdateHandlingException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Workflow\Event\CompletedEvent;

/**
 * The core admin order page has a Refund button that only moves the payment
 * state machine (sylius_payment refund transition). For EveryPay payments,
 * follow up with the actual refund API call via a refund payment request.
 *
 * The repository add() flushes mid-transition, which would persist the
 * `refunded` state before EveryPay confirmed anything - so everything runs
 * inside an explicit transaction, and failures are rethrown as
 * UpdateHandlingException: the resource controller catches it, shows an
 * error flash and never flushes, leaving the payment `completed` in the DB.
 *
 * @see \Pkg\SyliusEveryPayPlugin\CommandHandler\RefundEveryPayPaymentHandler
 */
#[AsEventListener(event: 'workflow.sylius_payment.completed.refund')]
final class RefundEveryPayPaymentListener
{
    /**
     * @param PaymentRequestFactoryInterface<PaymentRequestInterface> $paymentRequestFactory
     * @param PaymentRequestRepositoryInterface<PaymentRequestInterface> $paymentRequestRepository
     */
    public function __construct(
        #[Autowire(service: 'sylius.factory.payment_request')]
        private readonly PaymentRequestFactoryInterface $paymentRequestFactory,
        #[Autowire(service: 'sylius.repository.payment_request')]
        private readonly PaymentRequestRepositoryInterface $paymentRequestRepository,
        private readonly PaymentRequestAnnouncerInterface $paymentRequestAnnouncer,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(CompletedEvent $event): void
    {
        $payment = $event->getSubject();
        if (!$payment instanceof PaymentInterface) {
            return;
        }

        // A refund made in the EveryPay portal reaches us as a `refunded`
        // callback; the synchronizer marks its transition so we don't refund twice.
        if ($event->getContext()[EveryPayPaymentSynchronizer::WORKFLOW_CONTEXT] ?? false) {
            return;
        }

        $paymentMethod = $payment->getMethod();
        if (EveryPayGateway::FACTORY_NAME !== $paymentMethod?->getGatewayConfig()?->getFactoryName()) {
            return;
        }

        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $paymentRequest = $this->paymentRequestFactory->create($payment, $paymentMethod);
            $paymentRequest->setAction(PaymentRequestInterface::ACTION_REFUND);

            $this->paymentRequestRepository->add($paymentRequest);

            $this->paymentRequestAnnouncer->dispatchPaymentRequestCommand($paymentRequest);

            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();

            $this->logger->error('EveryPay refund failed - payment left as completed.', [
                'payment_id' => $payment->getId(),
                'exception' => $e,
            ]);

            throw new UpdateHandlingException(
                sprintf('EveryPay refund failed: %s', $e->getMessage()),
                'everypay_refund_failed',
                400,
                0,
                $e instanceof \Exception ? $e : null,
            );
        }
    }
}
