<?php

declare(strict_types=1);

namespace Tests\Pkg\SyliusEveryPayPlugin\Unit;

use PHPUnit\Framework\TestCase;
use Pkg\SyliusEveryPayPlugin\EveryPayGateway;

final class EveryPayGatewayTest extends TestCase
{
    public function testPaymentMethodOptionsKeepOnlyDirectlyLinkableEntries(): void
    {
        $options = EveryPayGateway::paymentMethodOptionsFrom([
            [
                'source' => 'swedbank_ob_lt',
                'display_name' => 'Swedbank',
                'country_code' => 'LT',
                'payment_link' => 'https://igw-demo.every-pay.com/lp/x/swedbank',
                'logo_url' => 'https://igw-demo.every-pay.com/assets/swedbank.svg',
                'tokenization_supported' => false,
            ],
            // No per-method link — cannot be shown as a direct button.
            ['source' => 'card', 'display_name' => 'VISA/MasterCard', 'payment_link' => null, 'logo_url' => 'x'],
            // Malformed entries are skipped defensively.
            'not-an-array',
            ['display_name' => 'No source', 'payment_link' => 'https://x'],
        ]);

        self::assertSame([
            [
                'source' => 'swedbank_ob_lt',
                'display_name' => 'Swedbank',
                'country_code' => 'LT',
                'payment_link' => 'https://igw-demo.every-pay.com/lp/x/swedbank',
                'logo_url' => 'https://igw-demo.every-pay.com/assets/swedbank.svg',
            ],
        ], $options);
    }

    public function testPaymentMethodOptionsFromNonArrayIsEmpty(): void
    {
        self::assertSame([], EveryPayGateway::paymentMethodOptionsFrom(null));
        self::assertSame([], EveryPayGateway::paymentMethodOptionsFrom('x'));
    }

    public function testDisplayModeDefaultsToRedirect(): void
    {
        self::assertSame(EveryPayGateway::DISPLAY_MODE_REDIRECT, EveryPayGateway::displayModeFrom([]));
        self::assertSame(EveryPayGateway::DISPLAY_MODE_REDIRECT, EveryPayGateway::displayModeFrom(['display_mode' => 'nonsense']));
        self::assertSame(EveryPayGateway::DISPLAY_MODE_METHOD_GRID, EveryPayGateway::displayModeFrom(['display_mode' => 'method_grid']));
    }
}
