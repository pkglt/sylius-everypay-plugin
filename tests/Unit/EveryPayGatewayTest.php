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

    public function testGroupingPutsThePreferredCountryFirstThenInternational(): void
    {
        $lt = ['source' => 'seb_ob_lt', 'display_name' => 'SEB LT', 'country_code' => 'LT', 'payment_link' => 'l1', 'logo_url' => null];
        $lt2 = ['source' => 'swed_ob_lt', 'display_name' => 'Swedbank LT', 'country_code' => 'LT', 'payment_link' => 'l2', 'logo_url' => null];
        $ee = ['source' => 'seb_ob_ee', 'display_name' => 'SEB EE', 'country_code' => 'EE', 'payment_link' => 'l3', 'logo_url' => null];
        $lv = ['source' => 'seb_ob_lv', 'display_name' => 'SEB LV', 'country_code' => 'LV', 'payment_link' => 'l4', 'logo_url' => null];
        $card = ['source' => 'card', 'display_name' => 'VISA/MasterCard', 'country_code' => null, 'payment_link' => 'l5', 'logo_url' => null];

        $groups = EveryPayGateway::groupPaymentMethodOptions([$ee, $card, $lt, $lv, $lt2], 'LT');

        self::assertSame(['LT', null, 'EE', 'LV'], array_column($groups, 'country_code'));
        // EveryPay's order is kept inside a group.
        self::assertSame(['seb_ob_lt', 'swed_ob_lt'], array_column($groups[0]['methods'], 'source'));
        self::assertSame(['card'], array_column($groups[1]['methods'], 'source'));
    }

    public function testGroupingWithoutPreferredCountryStartsInternational(): void
    {
        $lt = ['source' => 'seb_ob_lt', 'display_name' => 'SEB LT', 'country_code' => 'LT', 'payment_link' => 'l1', 'logo_url' => null];
        $card = ['source' => 'card', 'display_name' => 'Card', 'country_code' => null, 'payment_link' => 'l2', 'logo_url' => null];

        $groups = EveryPayGateway::groupPaymentMethodOptions([$lt, $card], null);

        self::assertSame([null, 'LT'], array_column($groups, 'country_code'));
    }

    public function testGroupingOfNothingIsEmpty(): void
    {
        self::assertSame([], EveryPayGateway::groupPaymentMethodOptions([], 'LT'));
    }

    public function testDisplayModeDefaultsToRedirect(): void
    {
        self::assertSame(EveryPayGateway::DISPLAY_MODE_REDIRECT, EveryPayGateway::displayModeFrom([]));
        self::assertSame(EveryPayGateway::DISPLAY_MODE_REDIRECT, EveryPayGateway::displayModeFrom(['display_mode' => 'nonsense']));
        self::assertSame(EveryPayGateway::DISPLAY_MODE_METHOD_GRID, EveryPayGateway::displayModeFrom(['display_mode' => 'method_grid']));
    }
}
