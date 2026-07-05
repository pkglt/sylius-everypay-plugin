<?php

declare(strict_types=1);

namespace Pkg\SyliusEveryPayPlugin;

use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;

/**
 * EveryPay (every-pay.com) gateway constants — factory name, gateway config
 * keys (admin-entered, encrypted at rest in sylius_gateway_config) and the
 * key under which EveryPay data is kept in sylius_payment.details.
 *
 * API reference: docs/everypay-api.md
 */
final class EveryPayGateway
{
    public const FACTORY_NAME = 'everypay';

    public const CONFIG_API_USERNAME = 'api_username';

    public const CONFIG_API_SECRET = 'api_secret';

    public const CONFIG_ACCOUNT_NAME = 'account_name';

    public const CONFIG_ENVIRONMENT = 'environment';

    public const ENVIRONMENT_DEMO = 'demo';

    public const ENVIRONMENT_LIVE = 'live';

    public const BASE_URLS = [
        self::ENVIRONMENT_DEMO => 'https://igw-demo.every-pay.com/api',
        self::ENVIRONMENT_LIVE => 'https://pay.every-pay.eu/api',
    ];

    /** Key inside Payment::getDetails() holding the EveryPay payment snapshot. */
    public const DETAILS_KEY = 'everypay';

    /**
     * @param array<array-key, mixed> $paymentDetails
     *
     * @return array<string, mixed>
     */
    public static function detailsFrom(array $paymentDetails): array
    {
        $details = $paymentDetails[self::DETAILS_KEY] ?? [];

        return is_array($details) ? $details : [];
    }

    /**
     * @param array<array-key, mixed> $paymentDetails
     */
    public static function paymentReferenceFrom(array $paymentDetails): ?string
    {
        $reference = self::detailsFrom($paymentDetails)['payment_reference'] ?? null;

        return is_string($reference) && '' !== $reference ? $reference : null;
    }

    public static function corePaymentFrom(PaymentRequestInterface $paymentRequest): PaymentInterface
    {
        $payment = $paymentRequest->getPayment();
        if (!$payment instanceof PaymentInterface) {
            throw new \LogicException('EveryPay payment request is not bound to a core payment.');
        }

        return $payment;
    }

    private function __construct()
    {
    }
}
