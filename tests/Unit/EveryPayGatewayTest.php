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
}
