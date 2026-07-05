<?php

declare(strict_types=1);

namespace Tests\Pkg\SyliusEveryPayPlugin\Client;

use PHPUnit\Framework\TestCase;
use Pkg\SyliusEveryPayPlugin\Client\EveryPayApiClient;
use Pkg\SyliusEveryPayPlugin\Client\EveryPayApiException;
use Pkg\SyliusEveryPayPlugin\Client\EveryPayCredentials;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class EveryPayApiClientTest extends TestCase
{
    private function credentials(): EveryPayCredentials
    {
        return new EveryPayCredentials(
            apiUsername: 'a04e7ce1060e7024',
            apiSecret: 'secret',
            accountName: 'EUR3D1',
            baseUrl: 'https://igw-demo.every-pay.com/api',
        );
    }

    public function testCreateOneOffPaymentSendsAuthAndDefaultBodyParameters(): void
    {
        $capturedOptions = null;
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedOptions): MockResponse {
            $capturedOptions = ['method' => $method, 'url' => $url, 'body' => json_decode((string) $options['body'], true)];

            return new MockResponse(json_encode([
                'payment_reference' => str_repeat('a', 64),
                'payment_link' => 'https://igw-demo.every-pay.com/lp/x/y',
                'payment_state' => 'initial',
            ], \JSON_THROW_ON_ERROR), ['http_code' => 201]);
        });

        $client = new EveryPayApiClient($httpClient);
        $response = $client->createOneOffPayment($this->credentials(), [
            'amount' => 12.34,
            'order_reference' => '000123-45',
            'customer_url' => 'https://shop.example/after-pay/hash',
        ]);

        self::assertNotNull($capturedOptions);
        self::assertSame('POST', $capturedOptions['method']);
        self::assertSame('https://igw-demo.every-pay.com/api/v4/payments/oneoff', $capturedOptions['url']);

        $body = $capturedOptions['body'];
        self::assertIsArray($body);
        self::assertSame('a04e7ce1060e7024', $body['api_username']);
        self::assertSame('EUR3D1', $body['account_name']);
        self::assertSame(12.34, $body['amount']);
        self::assertSame('000123-45', $body['order_reference']);
        self::assertNotEmpty($body['nonce']);
        self::assertNotEmpty($body['timestamp']);

        self::assertSame('initial', $response['payment_state']);
    }

    public function testGetPaymentBuildsReferenceUrlWithApiUsername(): void
    {
        $capturedUrl = null;
        $httpClient = new MockHttpClient(function (string $method, string $url) use (&$capturedUrl): MockResponse {
            $capturedUrl = $url;

            return new MockResponse(json_encode(['payment_state' => 'settled'], \JSON_THROW_ON_ERROR));
        });

        $client = new EveryPayApiClient($httpClient);
        $response = $client->getPayment($this->credentials(), 'abc123def456abc1');

        self::assertSame(
            'https://igw-demo.every-pay.com/api/v4/payments/abc123def456abc1?api_username=a04e7ce1060e7024',
            $capturedUrl,
        );
        self::assertSame('settled', $response['payment_state']);
    }

    public function testRefundPaymentSendsReferenceAndAmount(): void
    {
        $capturedBody = null;
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedBody): MockResponse {
            $capturedBody = json_decode((string) $options['body'], true);

            return new MockResponse(json_encode(['payment_state' => 'refunded'], \JSON_THROW_ON_ERROR), ['http_code' => 201]);
        });

        $client = new EveryPayApiClient($httpClient);
        $client->refundPayment($this->credentials(), 'abc123def456abc1', 12.34);

        self::assertIsArray($capturedBody);
        self::assertSame('abc123def456abc1', $capturedBody['payment_reference']);
        self::assertSame(12.34, $capturedBody['amount']);
        self::assertSame('a04e7ce1060e7024', $capturedBody['api_username']);
    }

    public function testNonSuccessResponseThrowsWithApiErrorMessage(): void
    {
        $httpClient = new MockHttpClient(new MockResponse(
            json_encode(['error' => ['code' => 4997, 'message' => 'The timestamp is not valid']], \JSON_THROW_ON_ERROR),
            ['http_code' => 400],
        ));

        $client = new EveryPayApiClient($httpClient);

        $this->expectException(EveryPayApiException::class);
        $this->expectExceptionMessageMatches('/The timestamp is not valid/');

        $client->createOneOffPayment($this->credentials(), []);
    }

    public function testTransportErrorIsWrappedInApiException(): void
    {
        $httpClient = new MockHttpClient(new MockResponse('', ['error' => 'Connection refused']));

        $client = new EveryPayApiClient($httpClient);

        $this->expectException(EveryPayApiException::class);

        $client->getPayment($this->credentials(), 'abc123def456abc1');
    }
}
