<?php

declare(strict_types=1);

namespace Pkg\SyliusEveryPayPlugin\Provider;

use Sylius\Component\Payment\Model\PaymentRequestInterface;

/**
 * Headless default: the API client that created the payment request supplies
 * the return URL itself, as `after_pay_url` in the payment request payload.
 * EveryPay rejects URLs with a dotless host (e.g. plain `localhost`).
 */
final class PayloadAfterPayUrlProvider implements AfterPayUrlProviderInterface
{
    public function getUrl(PaymentRequestInterface $paymentRequest): string
    {
        $url = $this->findUrl($paymentRequest);
        if (null === $url) {
            throw new \LogicException(sprintf(
                'No after-pay URL available for payment request "%s": pass "%s" in the payment request payload, or install sylius/shop-bundle to use the shop after-pay route.',
                (string) $paymentRequest->getId(),
                AfterPayUrlProviderInterface::PAYLOAD_KEY,
            ));
        }

        return $url;
    }

    public function findUrl(PaymentRequestInterface $paymentRequest): ?string
    {
        $payload = $paymentRequest->getPayload();
        if (!is_array($payload)) {
            return null;
        }

        $url = $payload[AfterPayUrlProviderInterface::PAYLOAD_KEY] ?? null;

        return is_string($url) && '' !== $url ? $url : null;
    }
}
