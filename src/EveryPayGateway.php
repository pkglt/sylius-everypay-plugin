<?php

declare(strict_types=1);

namespace Pkg\SyliusEveryPayPlugin;

use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;

/**
 * EveryPay (every-pay.com) gateway constants — factory name, gateway config
 * keys (admin-entered, encrypted at rest in sylius_gateway_config) and the
 * key under which EveryPay data is kept in sylius_payment.details.
 *
 * API reference: docs/everypay-api.md
 */
final class EveryPayGateway
{
    public const FACTORY_NAME = 'everypay';

    public const CONFIG_API_USERNAME = 'api_username';

    public const CONFIG_API_SECRET = 'api_secret';

    public const CONFIG_ACCOUNT_NAME = 'account_name';

    public const CONFIG_ENVIRONMENT = 'environment';

    public const CONFIG_DISPLAY_MODE = 'display_mode';

    /** Redirect straight to the EveryPay hosted payment page (default). */
    public const DISPLAY_MODE_REDIRECT = 'redirect';

    /** Show the payment methods (bank buttons) inside the shop first. */
    public const DISPLAY_MODE_METHOD_GRID = 'method_grid';

    public const ENVIRONMENT_DEMO = 'demo';

    public const ENVIRONMENT_LIVE = 'live';

    public const BASE_URLS = [
        self::ENVIRONMENT_DEMO => 'https://igw-demo.every-pay.com/api',
        self::ENVIRONMENT_LIVE => 'https://pay.every-pay.eu/api',
    ];

    /** Key inside Payment::getDetails() holding the EveryPay payment snapshot. */
    public const DETAILS_KEY = 'everypay';

    /**
     * @param array<array-key, mixed> $paymentDetails
     *
     * @return array<string, mixed>
     */
    public static function detailsFrom(array $paymentDetails): array
    {
        $details = $paymentDetails[self::DETAILS_KEY] ?? [];

        return is_array($details) ? $details : [];
    }

    /**
     * @param array<array-key, mixed> $paymentDetails
     */
    public static function paymentReferenceFrom(array $paymentDetails): ?string
    {
        $reference = self::detailsFrom($paymentDetails)['payment_reference'] ?? null;

        return is_string($reference) && '' !== $reference ? $reference : null;
    }

    /**
     * @param array<array-key, mixed> $config
     */
    public static function displayModeFrom(array $config): string
    {
        return self::DISPLAY_MODE_METHOD_GRID === ($config[self::CONFIG_DISPLAY_MODE] ?? null)
            ? self::DISPLAY_MODE_METHOD_GRID
            : self::DISPLAY_MODE_REDIRECT;
    }

    /**
     * Reduces the oneoff response `payment_methods` to the entries a shop can
     * link to directly (per-method payment_link present), keeping only the
     * presentation fields.
     *
     * @return list<array{source: string, display_name: string, country_code: ?string, payment_link: string, logo_url: ?string}>
     */
    public static function paymentMethodOptionsFrom(mixed $paymentMethods): array
    {
        if (!is_array($paymentMethods)) {
            return [];
        }

        $options = [];
        foreach ($paymentMethods as $method) {
            if (!is_array($method)) {
                continue;
            }
            $source = $method['source'] ?? null;
            $displayName = $method['display_name'] ?? null;
            $paymentLink = $method['payment_link'] ?? null;
            if (!is_string($source) || '' === $source || !is_string($displayName) || '' === $displayName || !is_string($paymentLink) || '' === $paymentLink) {
                continue;
            }

            $countryCode = $method['country_code'] ?? null;
            $logoUrl = $method['logo_url'] ?? null;
            $options[] = [
                'source' => $source,
                'display_name' => $displayName,
                'country_code' => is_string($countryCode) && '' !== $countryCode ? $countryCode : null,
                'payment_link' => $paymentLink,
                'logo_url' => is_string($logoUrl) && '' !== $logoUrl ? $logoUrl : null,
            ];
        }

        return $options;
    }

    /**
     * Groups grid options by country for display: the customer's (billing)
     * country first, then the international methods (card, Revolut — no
     * country_code), then the remaining countries in first-seen order.
     * Order inside each group is EveryPay's.
     *
     * @param list<array{source: string, display_name: string, country_code: ?string, payment_link: string, logo_url: ?string}> $options
     *
     * @return list<array{country_code: ?string, methods: non-empty-list<array{source: string, display_name: string, country_code: ?string, payment_link: string, logo_url: ?string}>}>
     */
    public static function groupPaymentMethodOptions(array $options, ?string $preferredCountry): array
    {
        $byCountry = [];
        foreach ($options as $option) {
            $byCountry[$option['country_code'] ?? ''][] = $option;
        }

        $keys = array_map(strval(...), array_keys($byCountry));
        usort($keys, static function (string $a, string $b) use ($preferredCountry, $byCountry): int {
            $rank = static function (string $key) use ($preferredCountry, $byCountry): array {
                if (null !== $preferredCountry && $key === $preferredCountry) {
                    return [0, 0];
                }
                if ('' === $key) {
                    return [1, 0];
                }

                return [2, array_search($key, array_keys($byCountry), true)];
            };

            return $rank($a) <=> $rank($b);
        });

        $groups = [];
        foreach ($keys as $key) {
            $groups[] = [
                'country_code' => '' === $key ? null : $key,
                'methods' => $byCountry[$key],
            ];
        }

        return $groups;
    }

    public static function corePaymentFrom(PaymentRequestInterface $paymentRequest): PaymentInterface
    {
        $payment = $paymentRequest->getPayment();
        if (!$payment instanceof PaymentInterface) {
            throw new \LogicException('EveryPay payment request is not bound to a core payment.');
        }

        return $payment;
    }

    private function __construct()
    {
    }
}
