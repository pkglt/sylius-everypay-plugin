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
     * Character limits EveryPay enforces on address fields - in demo from
     * 2026-11-01, in production from 2027-01-04 (255 across the board before
     * that). Over-long values are rejected, not cut, from then on.
     */
    private const ADDRESS_FIELD_LIMITS = [
        'city' => 50,
        'country' => 3,
        'line1' => 50,
        'postcode' => 16,
        'state' => 255,
    ];

    /** Free text that can be shortened; every other field is a code. */
    private const TRUNCATABLE_ADDRESS_FIELDS = ['city', 'line1'];

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

        // EveryPay asks for no shipping fields when nothing ships (digital
        // goods, services) - Sylius still keeps a shipping address there.
        return array_merge(
            $payload,
            $this->addressFields('billing', $billingAddress),
            $order->isShippingRequired() ? $this->addressFields('shipping', $order->getShippingAddress()) : [],
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
     * Bank-statement text for Open Banking payments: "{channel} ({number})" -
     * a language-neutral noun phrase that still reads naturally when the
     * EveryPay/LHV platform prefixes the refund transfer's copy of it with
     * "Refund - ". Capped at 65 characters by trimming the channel name, so
     * the order number always survives.
     */
    private function paymentDescription(OrderInterface $order): string
    {
        $suffix = sprintf('(%s)', $this->statementText((string) $order->getNumber()));
        $name = $this->statementText((string) $order->getChannel()?->getName());
        $name = rtrim(substr($name, 0, max(0, 65 - strlen($suffix) - 1)));

        return substr(trim($name . ' ' . $suffix), 0, 65);
    }

    /**
     * Reduces text to the charset EveryPay accepts ([a-zA-Z0-9/-?:().,'+ ] -
     * the SEPA set). Letters with diacritics are transliterated to their
     * ASCII base first; deleting them outright would garble the shop name on
     * the customer's statement.
     */
    private function statementText(string $text): string
    {
        $text = u($text)->ascii()->toString();
        $text = (string) preg_replace("#[^a-zA-Z0-9/?:().,'+ -]#", '', $text);

        return trim((string) preg_replace('/\s+/', ' ', $text));
    }

    private function resolveLocale(OrderInterface $order): string
    {
        $locale = strtolower(str_replace('-', '_', (string) $order->getLocaleCode()));
        $language = explode('_', $locale)[0];

        return in_array($language, self::ALLOWED_LOCALES, true) ? $language : 'en';
    }

    /**
     * Billing/shipping details improve card fraud scoring and 3DS approval
     * rates, so they are sent whenever known - but an over-long value fails
     * the whole payment request. Free text is shortened at a word boundary,
     * keeping the leading street name and house number EveryPay asks to
     * prioritize; a code that does not fit (a cut postcode is a wrong
     * postcode) is left out instead.
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
            $value = trim((string) preg_replace('/\s+/u', ' ', (string) $value));
            $limit = self::ADDRESS_FIELD_LIMITS[$suffix];

            if (in_array($suffix, self::TRUNCATABLE_ADDRESS_FIELDS, true)) {
                $value = $this->shortenAtWordBoundary($value, $limit);
            } elseif (mb_strlen($value) > $limit) {
                continue;
            }

            if ('' !== $value) {
                $fields[sprintf('%s_%s', $prefix, $suffix)] = $value;
            }
        }

        return $fields;
    }

    /** Character-based (diacritics count as one); a single over-long word is cut hard. */
    private function shortenAtWordBoundary(string $value, int $limit): string
    {
        if (mb_strlen($value) <= $limit) {
            return $value;
        }

        // One character past the limit: a space there means the cut already
        // falls on a word boundary.
        $head = mb_substr($value, 0, $limit + 1);
        $lastSpace = mb_strrpos($head, ' ');
        $head = false !== $lastSpace && $lastSpace > 0
            ? mb_substr($head, 0, $lastSpace)
            : mb_substr($value, 0, $limit);

        return rtrim($head, ' ,;-');
    }
}
