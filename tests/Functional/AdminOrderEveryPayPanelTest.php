<?php

declare(strict_types=1);

namespace Tests\Pkg\SyliusEveryPayPlugin\Functional;

use Pkg\SyliusEveryPayPlugin\EveryPayGateway;
use Symfony\Component\Routing\RouterInterface;

/**
 * The admin order page carries an extra cell per EveryPay payment: the raw
 * EveryPay state and the payment reference an operator needs to find the
 * payment in the merchant portal. Renders through the
 * sylius_admin.order.show.content.sections.payments.item twig hook.
 */
final class AdminOrderEveryPayPanelTest extends FunctionalTestCase
{
    private const PAYMENT_REFERENCE = 'abc123def456abc123def456abc123def456abc123def456abc123def456abcd';

    public function testEveryPayReferenceAndStateRenderOnTheOrderPage(): void
    {
        $client = static::createClient();
        $this->prepareDatabase();
        $channel = $this->createShopEnvironment();
        $method = $this->createEveryPayPaymentMethod($channel);
        $payment = $this->createOrderWithPayment($channel, $method, [
            EveryPayGateway::DETAILS_KEY => [
                'payment_reference' => self::PAYMENT_REFERENCE,
                'payment_state' => 'settled',
            ],
        ]);
        $admin = $this->shopFixtures()->createAdminUser();

        $client->loginUser($admin, 'admin');

        $order = $payment->getOrder();
        self::assertNotNull($order);
        $router = $this->service(RouterInterface::class, 'router');

        $client->request('GET', $router->generate('sylius_admin_order_show', ['id' => $order->getId()]));

        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();

        self::assertStringContainsString(self::PAYMENT_REFERENCE, $content);
        self::assertStringContainsString('data-test-everypay-state', $content);
        self::assertStringContainsString('settled', $content);

        // Demo environment: no live merchant portal link.
        self::assertStringNotContainsString('portal.every-pay.eu', $content);
    }
}
