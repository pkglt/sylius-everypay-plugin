<?php

declare(strict_types=1);

namespace Pkg\SyliusEveryPayPlugin\Factory;

/**
 * A customer phone number split the way EveryPay takes it - the
 * international dialling code and the national number, digits only. It feeds
 * the oneoff `phone_number` (3DS) and the Payment Elements SDK's
 * `phoneNumber` option (Click to Pay).
 *
 * Sylius stores phone numbers as free text, so the split is deliberately
 * conservative: only countries whose dialling rules are listed below are
 * understood, and anything ambiguous yields null. The field is optional for
 * EveryPay - no number is better than a wrong one.
 */
final readonly class EveryPayPhoneNumber
{
    /**
     * Country => [dialling code, national trunk prefixes]. A trunk prefix is
     * dialled only within the country and dropped internationally; countries
     * without one - or whose leading 0 belongs to the number, like Italy -
     * list none. Lithuanian numbers turn up written with either 8 or 0 in
     * front, so both are accepted. The codes are prefix-free, as E.164
     * guarantees.
     */
    private const DIALLING_RULES = [
        'AT' => ['43', ['0']],
        'BE' => ['32', ['0']],
        'BG' => ['359', ['0']],
        'CH' => ['41', ['0']],
        'CY' => ['357', []],
        'CZ' => ['420', []],
        'DE' => ['49', ['0']],
        'DK' => ['45', []],
        'EE' => ['372', []],
        'ES' => ['34', []],
        'FI' => ['358', ['0']],
        'FR' => ['33', ['0']],
        'GB' => ['44', ['0']],
        'GR' => ['30', []],
        'HR' => ['385', ['0']],
        'HU' => ['36', ['06']],
        'IE' => ['353', ['0']],
        'IS' => ['354', []],
        'IT' => ['39', []],
        'LI' => ['423', []],
        'LT' => ['370', ['8', '0']],
        'LU' => ['352', []],
        'LV' => ['371', []],
        'MT' => ['356', []],
        'NL' => ['31', ['0']],
        'NO' => ['47', []],
        'PL' => ['48', []],
        'PT' => ['351', []],
        'RO' => ['40', ['0']],
        'SE' => ['46', ['0']],
        'SI' => ['386', ['0']],
        'SK' => ['421', ['0']],
        'UA' => ['380', ['0']],
    ];

    private function __construct(
        public string $countryCode,
        public string $number,
    ) {
    }

    /**
     * Accepts international notation ("+370 612 34567", "00370...") for any
     * listed country, and national notation ("8 612 34567") for the given
     * address country. A trunk prefix is dropped in both, which also covers
     * the "+44 (0)20..." habit.
     */
    public static function fromSylius(?string $phoneNumber, ?string $countryCode): ?self
    {
        // Separators people type: whitespace, dashes (incl. typographic
        // ones), dots, slashes and parentheses.
        $compact = (string) preg_replace('/[\s\-\x{2010}-\x{2015}.\/()]+/u', '', (string) $phoneNumber);

        $international = null;
        if (str_starts_with($compact, '+')) {
            $international = substr($compact, 1);
        } elseif (str_starts_with($compact, '00')) {
            $international = substr($compact, 2);
        }

        // Letters, extensions or a stray "+" mean free text we cannot trust.
        if (1 !== preg_match('/^\d+$/', $international ?? $compact)) {
            return null;
        }

        if (null !== $international) {
            foreach (self::DIALLING_RULES as [$diallingCode, $trunkPrefixes]) {
                if (str_starts_with($international, $diallingCode)) {
                    return self::national($diallingCode, substr($international, strlen($diallingCode)), $trunkPrefixes);
                }
            }

            return null;
        }

        $rules = self::DIALLING_RULES[strtoupper((string) $countryCode)] ?? null;
        if (null === $rules) {
            return null;
        }

        return self::national($rules[0], $compact, $rules[1]);
    }

    /**
     * @return array{country_code: string, number: string}
     */
    public function toPayload(): array
    {
        return ['country_code' => $this->countryCode, 'number' => $this->number];
    }

    /**
     * @param list<string> $trunkPrefixes
     */
    private static function national(string $diallingCode, string $number, array $trunkPrefixes): ?self
    {
        foreach ($trunkPrefixes as $trunkPrefix) {
            if (str_starts_with($number, $trunkPrefix)) {
                $number = substr($number, strlen($trunkPrefix));

                break;
            }
        }

        // EveryPay takes 4 - 14 digits.
        return 1 === preg_match('/^\d{4,14}$/', $number) ? new self($diallingCode, $number) : null;
    }
}
