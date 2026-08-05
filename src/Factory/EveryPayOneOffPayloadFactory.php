<?php

declare(strict_types=1);

namespace Pkg\SyliusEveryPayPlugin\Factory;

use Composer\InstalledVersions;
use Pkg\SyliusEveryPayPlugin\EveryPayGateway;
use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use function Symfony\Component\String\u;

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

    /**
     * Character limits EveryPay enforces on address fields from 2026-10-01
     * (255 across the board before that).
     */
    private const ADDRESS_FIELD_LIMITS = [
        'city' => 50,
        'country' => 3,
        'line1' => 50,
        'postcode' => 16,
        'state' => 255,
    ];

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
            'order_reference' => $this->orderReference($order, $payment),
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
     * "{orderNumber}-{paymentId}" - unique per payment attempt: EveryPay
     * validates order_reference uniqueness per shop, and Sylius creates a new
     * Payment per retry. The order number is transliterated and reduced to
     * the charset EveryPay accepts ([a-zA-Z0-9/-?:().,'+] - no spaces) and
     * capped at 100 characters so the reference stays under the Open Banking
     * limit of 120: Sylius' default numeric order numbers pass untouched,
     * and the payment-id suffix keeps the reference unique regardless of
     * what sanitization does to a custom one.
     */
    private function orderReference(OrderInterface $order, PaymentInterface $payment): string
    {
        $number = u((string) $order->getNumber())->ascii()->toString();
        $number = (string) preg_replace("#[^a-zA-Z0-9/?:().,'+-]#", '', $number);

        return sprintf('%s-%d', substr($number, 0, 100), (int) $payment->getId());
    }

    /**
     * Bank-statement text for Open Banking payments: "{channel} order {number}",
     * reduced to the charset EveryPay accepts ([a-zA-Z0-9/-?:().,'+ ] - the
     * SEPA set) and capped at 65 characters. Letters with diacritics are
     * transliterated to their ASCII base first; deleting them outright would
     * garble the shop name on the customer's statement.
     */
    private function paymentDescription(OrderInterface $order): string
    {
        $text = sprintf('%s order %s', (string) $order->getChannel()?->getName(), (string) $order->getNumber());
        $text = u($text)->ascii()->toString();
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
     * increasingly expected by Visa/Mastercard. Values are capped at the
     * upcoming character limits - a truncated address still feeds fraud
     * scoring, an over-long one could fail the whole payment request.
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
                $fields[sprintf('%s_%s', $prefix, $suffix)] = mb_substr($value, 0, self::ADDRESS_FIELD_LIMITS[$suffix]);
            }
        }

        return $fields;
    }
}
