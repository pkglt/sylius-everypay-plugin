<?php

declare(strict_types=1);

namespace Tests\Pkg\SyliusEveryPayPlugin\Functional;

use Pkg\SyliusEveryPayPlugin\Client\EveryPayApiClient;
use Pkg\SyliusEveryPayPlugin\CommandHandler\CaptureEveryPayPaymentHandler;
use Pkg\SyliusEveryPayPlugin\CommandHandler\NotifyEveryPayPaymentHandler;
use Pkg\SyliusEveryPayPlugin\CommandHandler\RefundEveryPayPaymentHandler;
use Pkg\SyliusEveryPayPlugin\CommandHandler\StatusEveryPayPaymentHandler;
use Pkg\SyliusEveryPayPlugin\CommandProvider\EveryPayCommandProvider;
use Pkg\SyliusEveryPayPlugin\EventListener\RefundEveryPayPaymentListener;
use Pkg\SyliusEveryPayPlugin\Factory\EveryPayOneOffPayloadFactory;
use Pkg\SyliusEveryPayPlugin\Form\EveryPayGatewayConfigurationType;
use Pkg\SyliusEveryPayPlugin\Processor\EveryPayPaymentSynchronizer;
use Pkg\SyliusEveryPayPlugin\Processor\EveryPayStateMapper;
use Pkg\SyliusEveryPayPlugin\Provider\AfterPayUrlProviderInterface;
use Pkg\SyliusEveryPayPlugin\Provider\EveryPayHttpResponseProvider;
use Pkg\SyliusEveryPayPlugin\Provider\EveryPayNotifyPaymentProvider;
use Pkg\SyliusEveryPayPlugin\Provider\SyliusShopAfterPayUrlProvider;
use Symfony\Contracts\Translation\TranslatorInterface;
use Tests\Pkg\SyliusEveryPayPlugin\Functional\Support\EveryPayHttpMock;

/**
 * Boots the plugin inside the real Sylius test application and asserts the
 * wiring a DI smoke test cannot see: service resolution against live Sylius
 * dependencies, prepended app-level config, and translations.
 */
final class PluginWiringTest extends FunctionalTestCase
{
    public function testAllPluginServicesResolveInsideARealSyliusApplication(): void
    {
        static::bootKernel();

        foreach ([
            EveryPayApiClient::class,
            EveryPayCommandProvider::class,
            CaptureEveryPayPaymentHandler::class,
            StatusEveryPayPaymentHandler::class,
            NotifyEveryPayPaymentHandler::class,
            RefundEveryPayPaymentHandler::class,
            EveryPayOneOffPayloadFactory::class,
            EveryPayPaymentSynchronizer::class,
            EveryPayStateMapper::class,
            EveryPayHttpResponseProvider::class,
            EveryPayNotifyPaymentProvider::class,
            EveryPayGatewayConfigurationType::class,
            RefundEveryPayPaymentListener::class,
        ] as $serviceClass) {
            self::assertInstanceOf($serviceClass, static::getContainer()->get($serviceClass));
        }
    }

    public function testShopBundlePresenceSwitchesTheAfterPayUrlProviderToTheShopAwareOne(): void
    {
        static::bootKernel();

        self::assertInstanceOf(
            SyliusShopAfterPayUrlProvider::class,
            static::getContainer()->get(AfterPayUrlProviderInterface::class),
        );
    }

    public function testTheApiClientIsBackedByTheScriptedMockInTestEnvironment(): void
    {
        static::bootKernel();

        self::assertInstanceOf(EveryPayHttpMock::class, static::getContainer()->get(EveryPayHttpMock::class));
    }

    public function testGatewayValidationGroupsAreRegistered(): void
    {
        static::bootKernel();

        $found = false;
        foreach (static::getContainer()->getParameterBag()->all() as $name => $value) {
            if (str_contains((string) $name, 'validation_groups') && is_array($value) && ['sylius', 'everypay'] === ($value['everypay'] ?? null)) {
                $found = true;

                break;
            }
        }

        self::assertTrue($found, 'The everypay gateway validation groups ([sylius, everypay]) are not registered.');
    }

    public function testAdminFormTranslationsAreLoaded(): void
    {
        static::bootKernel();

        $translator = $this->service(TranslatorInterface::class, 'translator');

        self::assertSame('EveryPay (cards & bank payments)', $translator->trans('pkg_everypay.ui.gateway_label', [], 'messages', 'en'));
        self::assertSame('EveryPay (mokėjimo kortelės ir bankai)', $translator->trans('pkg_everypay.ui.gateway_label', [], 'messages', 'lt'));
        self::assertNotSame('sylius.payment.everypay_refund_failed', $translator->trans('sylius.payment.everypay_refund_failed', [], 'flashes', 'en'));
    }
}
