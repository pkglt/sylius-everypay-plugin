<?php

declare(strict_types=1);

namespace Tests\Pkg\SyliusEveryPayPlugin\Unit\Provider;

use PHPUnit\Framework\TestCase;
use Pkg\SyliusEveryPayPlugin\Provider\AfterPayUrlProviderInterface;
use Pkg\SyliusEveryPayPlugin\Provider\PayloadAfterPayUrlProvider;
use Pkg\SyliusEveryPayPlugin\Provider\SyliusShopAfterPayUrlProvider;
use Sylius\Bundle\CoreBundle\OrderPay\Provider\UrlProviderInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;

final class SyliusShopAfterPayUrlProviderTest extends TestCase
{
    public function testAnExplicitPayloadUrlWinsOverTheShopRoute(): void
    {
        $paymentRequest = $this->createStub(PaymentRequestInterface::class);
        $paymentRequest->method('getPayload')->willReturn([
            AfterPayUrlProviderInterface::PAYLOAD_KEY => 'https://spa.example/thank-you',
        ]);

        $shopUrlProvider = $this->createStub(UrlProviderInterface::class);
        $shopUrlProvider->method('getUrl')->willReturn('https://shop.example/order/after-pay/hash');

        $provider = new SyliusShopAfterPayUrlProvider(new PayloadAfterPayUrlProvider(), $shopUrlProvider);

        self::assertSame('https://spa.example/thank-you', $provider->getUrl($paymentRequest));
    }

    public function testFallsBackToTheShopAfterPayRoute(): void
    {
        $paymentRequest = $this->createStub(PaymentRequestInterface::class);
        $paymentRequest->method('getPayload')->willReturn(null);

        $shopUrlProvider = $this->createStub(UrlProviderInterface::class);
        $shopUrlProvider->method('getUrl')->willReturn('https://shop.example/order/after-pay/hash');

        $provider = new SyliusShopAfterPayUrlProvider(new PayloadAfterPayUrlProvider(), $shopUrlProvider);

        self::assertSame('https://shop.example/order/after-pay/hash', $provider->getUrl($paymentRequest));
    }
}
