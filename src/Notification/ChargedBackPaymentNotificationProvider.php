<?php

declare(strict_types=1);

namespace Pkg\SyliusEveryPayPlugin\Notification;

use Doctrine\ORM\EntityManagerInterface;
use Pkg\SyliusEveryPayPlugin\EveryPayGateway;
use Sylius\Bundle\AdminBundle\Notification\NotificationProviderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Surfaces charged-back EveryPay payments in the admin navbar bell.
 *
 * A chargeback deliberately never transitions the Sylius payment (see
 * EveryPayStateMapper) - the dispute is handled in the EveryPay merchant
 * portal. The synchronizer's log warning is invisible to shop staff, so each
 * payment whose stored EveryPay state is `charged_back` also becomes a
 * notification, and stays one until the dispute resolution callback updates
 * the stored state.
 *
 * The message is translated here, not in the template: the notifications
 * template contract only guarantees rendering `message` as-is (piped through
 * trans), so a self-contained string survives any template override.
 *
 * Registered by the plugin extension only when SyliusAdminBundle is present
 * (config/services/integrations/sylius_admin.php) - the class is excluded
 * from the service prototype and must not exist in adminless containers.
 */
final readonly class ChargedBackPaymentNotificationProvider implements NotificationProviderInterface
{
    private const REMOTE_STATE = 'charged_back';

    /** Navbar dropdown - chargebacks are rare; cap the list defensively. */
    private const MAX_NOTIFICATIONS = 10;

    /** @param class-string $paymentClass the app-configured sylius_payment resource class */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TranslatorInterface $translator,
        private string $paymentClass,
    ) {
    }

    public function getNotifications(array $context = []): array
    {
        $notifications = [];
        foreach ($this->chargedBackPayments() as $payment) {
            $entry = [
                'message' => $this->translator->trans('pkg_everypay.ui.notifications.charged_back', [
                    '%order_number%' => $payment->getOrder()?->getNumber() ?? (string) $payment->getId(),
                ]),
            ];

            $portalUrl = EveryPayGateway::merchantPortalUrlFrom(
                $payment->getMethod()?->getGatewayConfig()?->getConfig() ?? [],
            );
            if (null !== $portalUrl) {
                $entry['url'] = $portalUrl;
                $entry['url_label'] = 'pkg_everypay.ui.merchant_portal';
            }

            $notifications['pkg_everypay_charged_back_' . $payment->getId()] = $entry;
        }

        return $notifications;
    }

    public function supports(array $context = []): bool
    {
        return true;
    }

    /**
     * The scan runs on every admin page render; the gateway-scoped join plus
     * the LIMIT keep it cheap (wrap it in a short-TTL cache if that ever
     * changes). details is a JSON column (text on MySQL/MariaDB) and
     * `charged_back` only ever occurs there as the synchronizer-written
     * payment_state, so LIKE containment is precise enough - the same
     * portability trade-off as EveryPayNotifyPaymentProvider.
     *
     * @return list<PaymentInterface>
     */
    private function chargedBackPayments(): array
    {
        /** @var list<PaymentInterface> $payments */
        $payments = $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from($this->paymentClass, 'p')
            ->join('p.method', 'm')
            ->join('m.gatewayConfig', 'gc')
            ->andWhere('gc.factoryName = :factory')
            ->andWhere('p.details LIKE :state')
            ->setParameter('factory', EveryPayGateway::FACTORY_NAME)
            ->setParameter('state', '%' . self::REMOTE_STATE . '%')
            ->orderBy('p.id', 'DESC')
            ->setMaxResults(self::MAX_NOTIFICATIONS)
            ->getQuery()
            ->getResult();

        return $payments;
    }
}
