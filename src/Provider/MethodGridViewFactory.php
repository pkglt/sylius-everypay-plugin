<?php

declare(strict_types=1);

namespace Pkg\SyliusEveryPayPlugin\Provider;

/**
 * Shapes the oneoff response `payment_methods` for the in-shop method grid:
 * only entries a shop can link to directly (per-method payment_link present),
 * reduced to their presentation fields and grouped by country for display.
 */
final readonly class MethodGridViewFactory
{
    /**
     * @return list<array{source: string, display_name: string, country_code: ?string, payment_link: string, logo_url: ?string}>
     */
    public function optionsFrom(mixed $paymentMethods): array
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
     * country first, then the international methods (card, Revolut - no
     * country_code), then the remaining countries in first-seen order.
     * Order inside each group is EveryPay's.
     *
     * @param list<array{source: string, display_name: string, country_code: ?string, payment_link: string, logo_url: ?string}> $options
     *
     * @return list<array{country_code: ?string, methods: non-empty-list<array{source: string, display_name: string, country_code: ?string, payment_link: string, logo_url: ?string}>}>
     */
    public function group(array $options, ?string $preferredCountry): array
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
}
