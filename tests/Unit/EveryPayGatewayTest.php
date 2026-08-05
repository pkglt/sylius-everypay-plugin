<?php

declare(strict_types=1);

namespace Tests\Pkg\SyliusEveryPayPlugin\Unit;

use PHPUnit\Framework\TestCase;
use Pkg\SyliusEveryPayPlugin\EveryPayGateway;

final class EveryPayGatewayTest extends TestCase
{
    public function testDisplayModeDefaultsToRedirect(): void
    {
        self::assertSame(EveryPayGateway::DISPLAY_MODE_REDIRECT, EveryPayGateway::displayModeFrom([]));
        self::assertSame(EveryPayGateway::DISPLAY_MODE_REDIRECT, EveryPayGateway::displayModeFrom(['display_mode' => 'nonsense']));
        self::assertSame(EveryPayGateway::DISPLAY_MODE_METHOD_GRID, EveryPayGateway::displayModeFrom(['display_mode' => 'method_grid']));
    }

    public function testAmountToDecimalConvertsCents(): void
    {
        self::assertSame(25.99, EveryPayGateway::amountToDecimal(2599));
        self::assertSame(10.0, EveryPayGateway::amountToDecimal(1000));
        self::assertSame(0.0, EveryPayGateway::amountToDecimal(0));
    }

    public function testMerchantPortalUrlIsOnlyProvidedForLivePayments(): void
    {
        self::assertNull(EveryPayGateway::merchantPortalUrlFrom([]));
        self::assertNull(EveryPayGateway::merchantPortalUrlFrom(['environment' => 'demo']));
        self::assertNull(EveryPayGateway::merchantPortalUrlFrom([
            'environment' => 'demo',
            'merchant_portal_url' => 'https://portal.acquirer-bank.example/',
        ]));
    }

    public function testMerchantPortalUrlPrefersTheConfiguredAddress(): void
    {
        self::assertSame(
            EveryPayGateway::LIVE_MERCHANT_PORTAL_URL,
            EveryPayGateway::merchantPortalUrlFrom(['environment' => 'live']),
        );
        self::assertSame(
            EveryPayGateway::LIVE_MERCHANT_PORTAL_URL,
            EveryPayGateway::merchantPortalUrlFrom(['environment' => 'live', 'merchant_portal_url' => '']),
        );
        self::assertSame(
            'https://portal.acquirer-bank.example/',
            EveryPayGateway::merchantPortalUrlFrom([
                'environment' => 'live',
                'merchant_portal_url' => 'https://portal.acquirer-bank.example/',
            ]),
        );
    }
}
