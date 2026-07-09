<?php

declare(strict_types=1);

namespace Pkg\SyliusEveryPayPlugin\Client;

use Pkg\SyliusEveryPayPlugin\EveryPayGateway;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;

/**
 * Per-payment-method EveryPay credentials, read from the admin-entered
 * gateway configuration (decrypted transparently by Sylius on entity load).
 */
final readonly class EveryPayCredentials
{
    public function __construct(
        public string $apiUsername,
        public string $apiSecret,
        public string $accountName,
        public string $baseUrl,
    ) {
    }

    public static function fromPaymentMethod(?PaymentMethodInterface $paymentMethod): self
    {
        $gatewayConfig = $paymentMethod?->getGatewayConfig();
        if (!$gatewayConfig instanceof GatewayConfigInterface) {
            throw new \LogicException('EveryPay payment method has no gateway config.');
        }

        return self::fromGatewayConfig($gatewayConfig);
    }

    public static function fromGatewayConfig(GatewayConfigInterface $gatewayConfig): self
    {
        return self::fromConfig($gatewayConfig->getConfig());
    }

    /**
     * @param array<array-key, mixed> $config
     */
    public static function fromConfig(array $config): self
    {
        $environment = self::stringValue($config, EveryPayGateway::CONFIG_ENVIRONMENT);
        $baseUrl = EveryPayGateway::BASE_URLS[$environment] ?? EveryPayGateway::BASE_URLS[EveryPayGateway::ENVIRONMENT_DEMO];

        return new self(
            apiUsername: self::stringValue($config, EveryPayGateway::CONFIG_API_USERNAME),
            apiSecret: self::stringValue($config, EveryPayGateway::CONFIG_API_SECRET),
            accountName: self::stringValue($config, EveryPayGateway::CONFIG_ACCOUNT_NAME),
            baseUrl: $baseUrl,
        );
    }

    /**
     * EveryPay processing accounts are conventionally named after their
     * currency (EUR1, EUR3D1, ...) - a best-effort hint used only for a
     * logged warning, never to block a payment.
     */
    public function currencyHint(): ?string
    {
        return 1 === preg_match('/^([A-Z]{3})\d/', $this->accountName, $matches) ? $matches[1] : null;
    }

    /**
     * @param array<array-key, mixed> $config
     */
    private static function stringValue(array $config, string $key): string
    {
        $value = $config[$key] ?? null;

        return is_string($value) ? $value : '';
    }
}
