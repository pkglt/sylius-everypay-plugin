<?php

declare(strict_types=1);

namespace Tests\Pkg\SyliusEveryPayPlugin\Functional;

use Pkg\SyliusEveryPayPlugin\EveryPayGateway;
use Sylius\Component\Core\Factory\PaymentMethodFactoryInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Model\PaymentInterface as BasePaymentInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Routing\RouterInterface;

/**
 * Charged-back payments never transition the Sylius payment (the dispute is
 * handled in the EveryPay merchant portal) - instead every payment whose
 * stored EveryPay state is `charged_back` surfaces as an admin navbar
 * notification via ChargedBackPaymentNotificationProvider and the swapped
 * notifications component template.
 */
final class AdminChargebackNotificationTest extends FunctionalTestCase
{
    private const PAYMENT_REFERENCE = 'abc123def456abc123def456abc123def456abc123def456abc123def456abcd';

    private const MESSAGE = 'Payment for order 000000001 was charged back - review the dispute in the EveryPay merchant portal.';

    public function testAChargedBackLivePaymentSurfacesWithAPortalLink(): void
    {
        $content = $this->renderDashboard(gatewayConfigOverrides: [
            EveryPayGateway::CONFIG_ENVIRONMENT => EveryPayGateway::ENVIRONMENT_LIVE,
        ]);

        self::assertStringContainsString(self::MESSAGE, $content);
        self::assertStringContainsString('data-test-notification-link', $content);
        self::assertStringContainsString(EveryPayGateway::LIVE_MERCHANT_PORTAL_URL, $content);
    }

    public function testTheConfiguredMerchantPortalAddressWins(): void
    {
        $content = $this->renderDashboard(gatewayConfigOverrides: [
            EveryPayGateway::CONFIG_ENVIRONMENT => EveryPayGateway::ENVIRONMENT_LIVE,
            EveryPayGateway::CONFIG_MERCHANT_PORTAL_URL => 'https://portal.acquirer-bank.example/',
        ]);

        self::assertStringContainsString(self::MESSAGE, $content);
        self::assertStringContainsString('https://portal.acquirer-bank.example/', $content);
        self::assertStringNotContainsString(EveryPayGateway::LIVE_MERCHANT_PORTAL_URL, $content);
    }

    public function testADemoPaymentGetsANotificationWithoutAPortalLink(): void
    {
        $content = $this->renderDashboard();

        self::assertStringContainsString(self::MESSAGE, $content);
        self::assertStringNotContainsString('data-test-notification-link', $content);
    }

    public function testNoNotificationWithoutChargedBackPayments(): void
    {
        $content = $this->renderDashboard(everyPayState: 'settled');

        self::assertStringNotContainsString('was charged back', $content);
        self::assertStringNotContainsString('data-test-notification-link', $content);
    }

    public function testAChargedBackPaymentOfAnotherGatewayIsIgnored(): void
    {
        $client = static::createClient();
        $this->prepareDatabase();
        $channel = $this->createShopEnvironment();
        $method = $this->createOfflinePaymentMethod($channel);
        $this->shopFixtures()->createOrderWithPayment($channel, $method, [
            'offline_psp' => ['payment_state' => 'charged_back'],
        ], paymentState: BasePaymentInterface::STATE_COMPLETED);

        $content = $this->renderDashboardResponse($client);

        self::assertStringNotContainsString('was charged back', $content);
    }

    /**
     * @param array<string, mixed> $gatewayConfigOverrides
     */
    private function renderDashboard(array $gatewayConfigOverrides = [], string $everyPayState = 'charged_back'): string
    {
        $client = static::createClient();
        $this->prepareDatabase();
        $channel = $this->createShopEnvironment();
        $method = $this->createEveryPayPaymentMethod($channel, 'everypay', $gatewayConfigOverrides);
        $this->shopFixtures()->createOrderWithPayment($channel, $method, [
            EveryPayGateway::DETAILS_KEY => [
                'payment_reference' => self::PAYMENT_REFERENCE,
                'payment_state' => $everyPayState,
            ],
        ], paymentState: BasePaymentInterface::STATE_COMPLETED);

        return $this->renderDashboardResponse($client);
    }

    private function renderDashboardResponse(KernelBrowser $client): string
    {
        $admin = $this->shopFixtures()->createAdminUser();
        $client->loginUser($admin, 'admin');

        $router = $this->service(RouterInterface::class, 'router');
        $client->request('GET', $router->generate('sylius_admin_dashboard'));

        self::assertResponseIsSuccessful();

        return (string) $client->getResponse()->getContent();
    }

    private function createOfflinePaymentMethod(ChannelInterface $channel): PaymentMethodInterface
    {
        /** @var PaymentMethodFactoryInterface<PaymentMethodInterface> $factory */
        $factory = $this->service(PaymentMethodFactoryInterface::class, 'sylius.factory.payment_method');
        $method = $factory->createWithGateway('offline');
        $method->setCode('offline');
        $method->setCurrentLocale('en_US');
        $method->setFallbackLocale('en_US');
        $method->setName('Offline');
        $method->setEnabled(true);
        $method->addChannel($channel);
        $method->getGatewayConfig()?->setGatewayName('offline');

        $this->entityManager()->persist($method);
        $this->entityManager()->flush();

        return $method;
    }
}
