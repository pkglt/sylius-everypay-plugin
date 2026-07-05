<?php

declare(strict_types=1);

namespace Pkg\SyliusEveryPayPlugin\Provider;

use Sylius\Component\Payment\Model\PaymentRequestInterface;

/**
 * Provides the absolute URL EveryPay redirects the customer back to after the
 * hosted payment page (the oneoff `customer_url`). The default implementation
 * reads it from the payment request payload (headless/API checkouts); when
 * SyliusShopBundle is installed the shop's after-pay route is used as the
 * fallback for payloads without an explicit URL.
 */
interface AfterPayUrlProviderInterface
{
    /** Payment request payload key carrying the return URL in headless checkouts. */
    public const PAYLOAD_KEY = 'after_pay_url';

    public function getUrl(PaymentRequestInterface $paymentRequest): string;
}
