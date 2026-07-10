<?php

declare(strict_types=1);

namespace Pkg\SyliusEveryPayPlugin;

use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;

/**
 * EveryPay (every-pay.com) gateway constants - factory name, gateway config
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

    public const CONFIG_DISPLAY_MODE = 'display_mode';

    /** Redirect straight to the EveryPay hosted payment page (default). */
    public const DISPLAY_MODE_REDIRECT = 'redirect';

    /** Show the payment methods (bank buttons) inside the shop first. */
    public const DISPLAY_MODE_METHOD_GRID = 'method_grid';

    public const ENVIRONMENT_DEMO = 'demo';

    public const ENVIRONMENT_LIVE = 'live';

    public const BASE_URLS = [
        self::ENVIRONMENT_DEMO => 'https://igw-demo.every-pay.com/api',
        self::ENVIRONMENT_LIVE => 'https://pay.every-pay.eu/api',
    ];

    /** Key inside Payment::getDetails() holding the EveryPay payment snapshot. */
    public const DETAILS_KEY = 'everypay';

    /** Sent as integration_details.integration (EveryPay merchant telemetry). */
    public const INTEGRATION_NAME = 'pkglt/sylius-everypay-plugin';

    /** Linked from the admin order panel for live payments. */
    public const LIVE_MERCHANT_PORTAL_URL = 'https://portal.every-pay.eu/';

    /** Sylius stores amounts in cents; the EveryPay API expects a 2-decimal number. */
    public static function amountToDecimal(int $amountInCents): float
    {
        return round($amountInCents / 100, 2);
    }

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

    /**
     * @param array<array-key, mixed> $config
     */
    public static function displayModeFrom(array $config): string
    {
        return self::DISPLAY_MODE_METHOD_GRID === ($config[self::CONFIG_DISPLAY_MODE] ?? null)
            ? self::DISPLAY_MODE_METHOD_GRID
            : self::DISPLAY_MODE_REDIRECT;
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
