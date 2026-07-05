<?php

declare(strict_types=1);

namespace Pkg\SyliusEveryPayPlugin\Provider;

use Sylius\Bundle\CoreBundle\OrderPay\Provider\UrlProviderInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Used when SyliusShopBundle is installed (registered conditionally by the
 * plugin extension — this class is deliberately excluded from the service
 * prototype). An explicit payload URL still wins so API-created payment
 * requests keep working in hybrid shop+API applications; the shop's
 * /order/after-pay/{hash} route is the fallback.
 */
final class SyliusShopAfterPayUrlProvider implements AfterPayUrlProviderInterface
{
    public function __construct(
        private readonly PayloadAfterPayUrlProvider $payloadAfterPayUrlProvider,
        private readonly UrlProviderInterface $shopAfterPayUrlProvider,
    ) {
    }

    public function getUrl(PaymentRequestInterface $paymentRequest): string
    {
        return $this->payloadAfterPayUrlProvider->findUrl($paymentRequest)
            ?? $this->shopAfterPayUrlProvider->getUrl($paymentRequest, UrlGeneratorInterface::ABSOLUTE_URL);
    }
}
