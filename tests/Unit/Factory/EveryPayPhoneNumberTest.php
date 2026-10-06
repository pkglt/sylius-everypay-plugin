<?php

declare(strict_types=1);

namespace Tests\Pkg\SyliusEveryPayPlugin\Unit\Factory;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pkg\SyliusEveryPayPlugin\Factory\EveryPayPhoneNumber;

final class EveryPayPhoneNumberTest extends TestCase
{
    /**
     * @return iterable<string, array{string, ?string, string, string}>
     */
    public static function understoodNumbers(): iterable
    {
        yield 'Lithuanian, international' => ['+370 612 34567', 'LT', '370', '61234567'];
        yield 'Lithuanian, 00 prefix' => ['00370 612 34567', 'LT', '370', '61234567'];
        yield 'Lithuanian, national with 8' => ['8 612 34567', 'LT', '370', '61234567'];
        yield 'Lithuanian, national with 0' => ['0612-34567', 'LT', '370', '61234567'];
        yield 'Lithuanian, international with a stray 8' => ['+370 (8) 612 34567', 'LT', '370', '61234567'];
        yield 'Estonian, no trunk prefix' => ['5123 4567', 'EE', '372', '51234567'];
        yield 'Latvian, no trunk prefix' => ['2123 4567', 'LV', '371', '21234567'];
        yield 'British "(0)" habit' => ['+44 (0)20 7946 0958', 'LT', '44', '2079460958'];
        yield 'German, national' => ['030 / 123 456 78', 'DE', '49', '3012345678'];
        yield 'Italian landline keeps its 0' => ['+39 06 1234 5678', 'IT', '39', '0612345678'];
        yield 'Hungarian 06 trunk prefix' => ['06 30 123 4567', 'HU', '36', '301234567'];
        yield 'international ignores the address country' => ['+371 2123 4567', 'LT', '371', '21234567'];
        yield 'typographic dashes and dots' => ["+370\u{2011}612.34567", null, '370', '61234567'];
    }

    #[DataProvider('understoodNumbers')]
    public function testSplitsNumbersItUnderstands(string $phoneNumber, ?string $countryCode, string $expectedCode, string $expectedNumber): void
    {
        $split = EveryPayPhoneNumber::fromSylius($phoneNumber, $countryCode);

        self::assertNotNull($split);
        self::assertSame(['country_code' => $expectedCode, 'number' => $expectedNumber], $split->toPayload());
    }

    /**
     * @return iterable<string, array{?string, ?string}>
     */
    public static function rejectedNumbers(): iterable
    {
        yield 'empty' => ['', 'LT'];
        yield 'missing' => [null, 'LT'];
        yield 'national without an address country' => ['8 612 34567', null];
        yield 'national for an unlisted country' => ['(212) 555-0100', 'US'];
        yield 'international with an unlisted code' => ['+1 212 555 0100', 'LT'];
        yield 'letters' => ['call me', 'LT'];
        yield 'an extension' => ['+370 612 34567 ext 12', 'LT'];
        yield 'too short' => ['+370 123', 'LT'];
        yield 'too long' => ['+370 1234 5678 9012 345', 'LT'];
        yield 'a lone plus' => ['+', 'LT'];
    }

    #[DataProvider('rejectedNumbers')]
    public function testLeavesOutWhatItCannotSplitSafely(?string $phoneNumber, ?string $countryCode): void
    {
        self::assertNull(EveryPayPhoneNumber::fromSylius($phoneNumber, $countryCode));
    }
}
