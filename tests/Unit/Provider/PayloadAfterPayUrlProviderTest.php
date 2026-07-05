<?php

declare(strict_types=1);

namespace Tests\Pkg\SyliusEveryPayPlugin\Unit\Provider;

use PHPUnit\Framework\TestCase;
use Pkg\SyliusEveryPayPlugin\Provider\AfterPayUrlProviderInterface;
use Pkg\SyliusEveryPayPlugin\Provider\PayloadAfterPayUrlProvider;
use Sylius\Component\Payment\Model\PaymentRequestInterface;

final class PayloadAfterPayUrlProviderTest extends TestCase
{
    private PayloadAfterPayUrlProvider $provider;

    protected function setUp(): void
    {
        $this->provider = new PayloadAfterPayUrlProvider();
    }

    public function testReturnsTheUrlFromThePaymentRequestPayload(): void
    {
        $paymentRequest = $this->paymentRequestWithPayload([
            AfterPayUrlProviderInterface::PAYLOAD_KEY => 'https://spa.example/checkout/thank-you',
        ]);

        self::assertSame('https://spa.example/checkout/thank-you', $this->provider->getUrl($paymentRequest));
    }

    public function testFindUrlReturnsNullWhenPayloadHasNoUrl(): void
    {
        self::assertNull($this->provider->findUrl($this->paymentRequestWithPayload(null)));
        self::assertNull($this->provider->findUrl($this->paymentRequestWithPayload([])));
        self::assertNull($this->provider->findUrl($this->paymentRequestWithPayload([AfterPayUrlProviderInterface::PAYLOAD_KEY => ''])));
        self::assertNull($this->provider->findUrl($this->paymentRequestWithPayload([AfterPayUrlProviderInterface::PAYLOAD_KEY => 123])));
    }

    public function testThrowsAnActionableErrorWhenNoUrlIsAvailable(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/after_pay_url.*sylius\/shop-bundle/');

        $this->provider->getUrl($this->paymentRequestWithPayload([]));
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    private function paymentRequestWithPayload(?array $payload): PaymentRequestInterface
    {
        $paymentRequest = $this->createStub(PaymentRequestInterface::class);
        $paymentRequest->method('getPayload')->willReturn($payload);
        $paymentRequest->method('getId')->willReturn('hash');

        return $paymentRequest;
    }
}
