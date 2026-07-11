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
        self::assertSame(EveryPayGateway::DISPLAY_MODE_PAYMENT_ELEMENTS, EveryPayGateway::displayModeFrom(['display_mode' => 'payment_elements']));
    }

    public function testEnvironmentDefaultsToDemo(): void
    {
        self::assertSame(EveryPayGateway::ENVIRONMENT_DEMO, EveryPayGateway::environmentFrom([]));
        self::assertSame(EveryPayGateway::ENVIRONMENT_DEMO, EveryPayGateway::environmentFrom(['environment' => 'nonsense']));
        self::assertSame(EveryPayGateway::ENVIRONMENT_LIVE, EveryPayGateway::environmentFrom(['environment' => 'live']));
    }

    public function testAmountToDecimalConvertsCents(): void
    {
        self::assertSame(25.99, EveryPayGateway::amountToDecimal(2599));
        self::assertSame(10.0, EveryPayGateway::amountToDecimal(1000));
        self::assertSame(0.0, EveryPayGateway::amountToDecimal(0));
    }
}
