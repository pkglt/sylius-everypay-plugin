<?php

declare(strict_types=1);

namespace Pkg\SyliusEveryPayPlugin\Factory;

use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Builds the business fields of a POST /v4/payments/oneoff request from a
 * Sylius payment. Authentication fields (api_username, nonce, timestamp,
 * account_name) are added by the API client.
 */
final class EveryPayOneOffPayloadFactory
{
    /** Locales accepted by the EveryPay hosted payment page. */
    private const ALLOWED_LOCALES = [
        'cz', 'da', 'de', 'en', 'es', 'et', 'fi', 'fr', 'hu', 'it',
        'lt', 'lv', 'nl', 'no', 'pl', 'pt', 'ru', 'sk', 'sv', 'uk',
    ];

    private const PREFERRED_COUNTRIES = ['EE', 'LV', 'LT'];

    public function __construct(
        private readonly RequestStack $requestStack,
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
            // Sylius stores amounts in cents; EveryPay expects a 2-digit decimal
            'amount' => round(((int) $payment->getAmount()) / 100, 2),
            // Unique per payment attempt: EveryPay validates order_reference
            // uniqueness per shop, and Sylius creates a new Payment per retry
            'order_reference' => sprintf('%s-%d', (string) $order->getNumber(), (int) $payment->getId()),
            'customer_url' => $customerUrl,
            'locale' => $this->resolveLocale($order),
            'integration_details' => [
                'integration' => 'custom',
                'software' => 'Sylius',
                'version' => '2.2',
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
        ] as $suffix => $value) {
            if (null !== $value && '' !== $value) {
                $fields[sprintf('%s_%s', $prefix, $suffix)] = $value;
            }
        }

        return $fields;
    }
}
