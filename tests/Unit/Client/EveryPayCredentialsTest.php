<?php

declare(strict_types=1);

namespace Tests\Pkg\SyliusEveryPayPlugin\Unit\Client;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pkg\SyliusEveryPayPlugin\Client\EveryPayCredentials;

final class EveryPayCredentialsTest extends TestCase
{
    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function accountNameProvider(): iterable
    {
        yield 'plain currency account' => ['EUR1', 'EUR'];
        yield '3DS currency account' => ['EUR3D1', 'EUR'];
        yield 'other currency' => ['USD2', 'USD'];
        yield 'no digit after prefix is not a hint' => ['MAIN', null];
        yield 'four letters are not a hint' => ['EURO1', null];
        yield 'lowercase is not a hint' => ['eur1', null];
        yield 'empty account' => ['', null];
    }

    #[DataProvider('accountNameProvider')]
    public function testCurrencyHintIsDerivedFromTheAccountNameConvention(string $accountName, ?string $expectedHint): void
    {
        $credentials = new EveryPayCredentials(
            apiUsername: 'abcd1234abcd1234',
            apiSecret: 'secret',
            accountName: $accountName,
            baseUrl: 'https://igw-demo.every-pay.com/api',
        );

        self::assertSame($expectedHint, $credentials->currencyHint());
    }
}
