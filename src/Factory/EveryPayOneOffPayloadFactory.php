<?php

declare(strict_types=1);

namespace Pkg\SyliusEveryPayPlugin\Factory;

use Composer\InstalledVersions;
use Pkg\SyliusEveryPayPlugin\EveryPayGateway;
use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Builds the business fields of a POST /v4/payments/oneoff request from a
 * Sylius payment. Authentication fields (api_username, nonce, timestamp,
 * account_name) are added by the API client.
 */
final readonly class EveryPayOneOffPayloadFactory
{
    /** Locales accepted by the EveryPay hosted payment page. */
    private const ALLOWED_LOCALES = [
        'cz', 'da', 'de', 'en', 'es', 'et', 'fi', 'fr', 'hu', 'it',
        'lt', 'lv', 'nl', 'no', 'pl', 'pt', 'ru', 'sk', 'sv', 'uk',
    ];

    private const PREFERRED_COUNTRIES = ['EE', 'LV', 'LT'];

    public function __construct(
        private RequestStack $requestStack,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function create(PaymentInterface $payment, string $customerUrl): array
    {
        $order = $payment->getOrder();
        if (!$order instanceof OrderInterface) {
            throw new \LogicException('EveryPay payment has no order.');
        }

        $payload = [
            'amount' => EveryPayGateway::amountToDecimal((int) $payment->getAmount()),
            // Unique per payment attempt: EveryPay validates order_reference
            // uniqueness per shop, and Sylius creates a new Payment per retry
            'order_reference' => sprintf('%s-%d', (string) $order->getNumber(), (int) $payment->getId()),
            'customer_url' => $customerUrl,
            'locale' => $this->resolveLocale($order),
            'payment_description' => $this->paymentDescription($order),
            'integration_details' => [
                'integration' => EveryPayGateway::INTEGRATION_NAME,
                'software' => 'Sylius',
                'version' => $this->integrationVersion(),
            ],
        ];

        $email = $order->getCustomer()?->getEmail();
        if (null !== $email && '' !== $email) {
            $payload['email'] = $email;
        }

        // The IP Sylius captured at checkout survives even if the command bus
        // ever goes async; the current request is the fallback.
        $customerIp = $order->getCustomerIp() ?? $this->requestStack->getMainRequest()?->getClientIp();
        if (null !== $customerIp) {
            $payload['customer_ip'] = $customerIp;
        }

        $billingAddress = $order->getBillingAddress();
        $billingCountry = $billingAddress?->getCountryCode();
        if (null !== $billingCountry && in_array($billingCountry, self::PREFERRED_COUNTRIES, true)) {
            $payload['preferred_country'] = $billingCountry;
        }

        return array_merge(
            $payload,
            $this->addressFields('billing', $billingAddress),
            $this->addressFields('shipping', $order->getShippingAddress()),
        );
    }

    /** Installed plugin version; "dev" when package metadata is unavailable. */
    private function integrationVersion(): string
    {
        if (InstalledVersions::isInstalled(EveryPayGateway::INTEGRATION_NAME)) {
            return InstalledVersions::getPrettyVersion(EveryPayGateway::INTEGRATION_NAME) ?? 'dev';
        }

        return 'dev';
    }

    /**
     * Bank-statement text for Open Banking payments: "{channel} order {number}",
     * reduced to the charset EveryPay accepts ([a-zA-Z0-9/-?:().,'+ ] - the
     * SEPA set, so diacritics are stripped) and capped at 65 characters.
     */
    private function paymentDescription(OrderInterface $order): string
    {
        $text = sprintf('%s order %s', (string) $order->getChannel()?->getName(), (string) $order->getNumber());
        $text = (string) preg_replace("#[^a-zA-Z0-9/?:().,'+ -]#", '', $text);
        $text = trim((string) preg_replace('/\s+/', ' ', $text));

        return substr($text, 0, 65);
    }

    private function resolveLocale(OrderInterface $order): string
    {
        $locale = strtolower(str_replace('-', '_', (string) $order->getLocaleCode()));
        $language = explode('_', $locale)[0];

        return in_array($language, self::ALLOWED_LOCALES, true) ? $language : 'en';
    }

    /**
     * Billing/shipping details improve card fraud scoring and will become
     * increasingly expected by Visa/Mastercard (EveryPay spec note, 2026-10).
     *
     * @return array<string, string>
     */
    private function addressFields(string $prefix, ?AddressInterface $address): array
    {
        if (null === $address) {
            return [];
        }

        $fields = [];
        foreach ([
            'city' => $address->getCity(),
            'country' => $address->getCountryCode(),
            'line1' => $address->getStreet(),
            'postcode' => $address->getPostcode(),
            'state' => $address->getProvinceCode(),
        ] as $suffix => $value) {
            if (null !== $value && '' !== $value) {
                $fields[sprintf('%s_%s', $prefix, $suffix)] = $value;
            }
        }

        return $fields;
    }
}
