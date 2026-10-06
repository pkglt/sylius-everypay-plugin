<?php

declare(strict_types=1);

namespace Pkg\SyliusEveryPayPlugin;

use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
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

    /**
     * Optional: the merchant portal address linked from the admin order page.
     * Acquiring banks white-label the EveryPay portal under their own domain,
     * so the right address depends on who issued the credentials; empty falls
     * back to LIVE_MERCHANT_PORTAL_URL.
     */
    public const CONFIG_MERCHANT_PORTAL_URL = 'merchant_portal_url';

    /** Redirect straight to the EveryPay hosted payment page (default). */
    public const DISPLAY_MODE_REDIRECT = 'redirect';

    /** Show the payment methods (bank buttons) inside the shop first. */
    public const DISPLAY_MODE_METHOD_GRID = 'method_grid';

    /**
     * Render the EveryPay Payment Elements embedded checkout inside the shop.
     * Experimental until it has run in production - the contract follows
     * EveryPay's Payment Elements integration guide (see
     * docs/everypay-api.md#payment-elements-embedded-checkout).
     */
    public const DISPLAY_MODE_PAYMENT_ELEMENTS = 'payment_elements';

    public const ENVIRONMENT_DEMO = 'demo';

    public const ENVIRONMENT_LIVE = 'live';

    public const BASE_URLS = [
        self::ENVIRONMENT_DEMO => 'https://igw-demo.every-pay.com/api',
        self::ENVIRONMENT_LIVE => 'https://pay.every-pay.eu/api',
    ];

    /** The Payment Elements browser SDK bundle, served from the gateway host. */
    public const ELEMENTS_SDK_URLS = [
        self::ENVIRONMENT_DEMO => 'https://igw-demo.every-pay.com/payment_elements/everypay-sdk-v1-0-0.umd.js',
        self::ENVIRONMENT_LIVE => 'https://pay.every-pay.eu/payment_elements/everypay-sdk-v1-0-0.umd.js',
    ];

    /** Gateway environment -> the value of the SDK's `environment` option. */
    public const ELEMENTS_SDK_ENVIRONMENTS = [
        self::ENVIRONMENT_DEMO => 'demo',
        self::ENVIRONMENT_LIVE => 'production',
    ];

    /** Key inside Payment::getDetails() holding the EveryPay payment snapshot. */
    public const DETAILS_KEY = 'everypay';

    /**
     * Indicators for responseData['error']. Sylius serializes responseData to the
     * shopper, so raw exception text must never go there - it carries the
     * api_username and the gateway body. Detail belongs in the everypay log only.
     */
    public const ERROR_GATEWAY_UNAVAILABLE = 'gateway_unavailable';

    /** EveryPay answered 2xx, but without the fields the flow needs. */
    public const ERROR_INVALID_GATEWAY_RESPONSE = 'invalid_gateway_response';

    /** Sent as integration_details.integration (EveryPay merchant telemetry). */
    public const INTEGRATION_NAME = 'pkglt/sylius-everypay-plugin';

    /**
     * Linked from the admin order panel for live payments when no
     * CONFIG_MERCHANT_PORTAL_URL is configured.
     */
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
     * Merges an authoritative payment payload (a GET /v4/payments/{ref} or
     * refund response body) into the everypay section of the payment details,
     * preserving capture-time keys such as payment_reference and payment_link.
     *
     * @param array<array-key, mixed> $paymentDetails
     * @param array<string, mixed> $remote
     *
     * @return array<array-key, mixed>
     */
    public static function withRemoteSnapshot(array $paymentDetails, array $remote): array
    {
        $paymentDetails[self::DETAILS_KEY] = array_merge(self::detailsFrom($paymentDetails), [
            'payment_state' => $remote['payment_state'] ?? null,
            'payment_method' => $remote['payment_method'] ?? null,
            'standing_amount' => $remote['standing_amount'] ?? null,
            'synchronized_at' => $remote['payment_created_at'] ?? null,
        ]);

        return $paymentDetails;
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
     * The merchant portal address admin pages link to: the configured
     * white-label address when set, the standard EveryPay portal otherwise -
     * and only for live payments (the demo environment has no portal
     * counterpart worth linking).
     *
     * @param array<array-key, mixed> $config
     */
    public static function merchantPortalUrlFrom(array $config): ?string
    {
        if (self::ENVIRONMENT_LIVE !== ($config[self::CONFIG_ENVIRONMENT] ?? null)) {
            return null;
        }

        $configured = $config[self::CONFIG_MERCHANT_PORTAL_URL] ?? null;

        return is_string($configured) && '' !== $configured ? $configured : self::LIVE_MERCHANT_PORTAL_URL;
    }

    /**
     * @param array<array-key, mixed> $config
     */
    public static function displayModeFrom(array $config): string
    {
        $mode = $config[self::CONFIG_DISPLAY_MODE] ?? null;

        return in_array($mode, [self::DISPLAY_MODE_METHOD_GRID, self::DISPLAY_MODE_PAYMENT_ELEMENTS], true)
            ? $mode
            : self::DISPLAY_MODE_REDIRECT;
    }

    public static function displayModeFor(PaymentRequestInterface $paymentRequest): string
    {
        $gatewayConfig = $paymentRequest->getMethod()->getGatewayConfig();

        return $gatewayConfig instanceof GatewayConfigInterface
            ? self::displayModeFrom($gatewayConfig->getConfig())
            : self::DISPLAY_MODE_REDIRECT;
    }

    /**
     * @param array<array-key, mixed> $config
     */
    public static function environmentFrom(array $config): string
    {
        return self::ENVIRONMENT_LIVE === ($config[self::CONFIG_ENVIRONMENT] ?? null)
            ? self::ENVIRONMENT_LIVE
            : self::ENVIRONMENT_DEMO;
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
