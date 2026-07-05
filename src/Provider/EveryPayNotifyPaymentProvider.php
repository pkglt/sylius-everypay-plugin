<?php

declare(strict_types=1);

namespace Pkg\SyliusEveryPayPlugin\Provider;

use Doctrine\ORM\EntityManagerInterface;
use Pkg\SyliusEveryPayPlugin\EveryPayGateway;
use Sylius\Bundle\PaymentBundle\Attribute\AsNotifyPaymentProvider;
use Sylius\Bundle\PaymentBundle\Provider\NotifyPaymentProviderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentInterface as BasePaymentInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Resolves the Sylius payment targeted by an EveryPay callback hitting
 * /payment-methods/{code}?payment_reference=…&event_name=… — the reference
 * was stored in payment details when the one-off payment was created.
 * The callback itself is never trusted; the notify handler re-reads the
 * state from the EveryPay API.
 */
#[AsNotifyPaymentProvider]
final class EveryPayNotifyPaymentProvider implements NotifyPaymentProviderInterface
{
    /** EveryPay payment_reference is a long hex string. */
    private const PAYMENT_REFERENCE_PATTERN = '/^[a-f0-9]{16,128}$/i';

    /**
     * @param class-string $paymentClass the app-configured sylius_payment resource class
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        #[Autowire(param: 'sylius.model.payment.class')]
        private readonly string $paymentClass,
    ) {
    }

    public function supports(Request $request, PaymentMethodInterface $paymentMethod): bool
    {
        return EveryPayGateway::FACTORY_NAME === $paymentMethod->getGatewayConfig()?->getFactoryName();
    }

    public function getPayment(Request $request, PaymentMethodInterface $paymentMethod): BasePaymentInterface
    {
        $paymentReference = (string) $request->query->get('payment_reference', '');
        if (1 !== preg_match(self::PAYMENT_REFERENCE_PATTERN, $paymentReference)) {
            throw new BadRequestHttpException('Missing or malformed payment_reference.');
        }

        // details is a JSON column (serialized as text on MySQL/MariaDB); the
        // 16+ char hex reference is unique enough for a LIKE containment match
        $payment = $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from($this->paymentClass, 'p')
            ->andWhere('p.method = :method')
            ->andWhere('p.details LIKE :reference')
            ->setParameter('method', $paymentMethod)
            ->setParameter('reference', '%' . $paymentReference . '%')
            ->orderBy('p.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$payment instanceof PaymentInterface) {
            throw new NotFoundHttpException(sprintf('No payment found for EveryPay reference "%s".', $paymentReference));
        }

        return $payment;
    }
}
