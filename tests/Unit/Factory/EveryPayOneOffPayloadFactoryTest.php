<?php

declare(strict_types=1);

namespace Tests\Pkg\SyliusEveryPayPlugin\Unit\Factory;

use PHPUnit\Framework\TestCase;
use Pkg\SyliusEveryPayPlugin\Factory\EveryPayOneOffPayloadFactory;
use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class EveryPayOneOffPayloadFactoryTest extends TestCase
{
    private const CUSTOMER_URL = 'https://shop.example/after-pay/hash';

    public function testBuildsFullPayloadFromLithuanianOrder(): void
    {
        $factory = new EveryPayOneOffPayloadFactory($this->requestStackWithClientIp('203.0.113.7'));

        $payload = $factory->create(
            $this->payment(
                amount: 2599,
                paymentId: 45,
                orderNumber: '000123',
                localeCode: 'lt_LT',
                email: 'client@example.com',
                billingAddress: $this->address('Kaunas', 'LT', 'Savanorių pr. 1', '44255', 'LT-KU'),
                shippingAddress: $this->address('Vilnius', 'LT', 'Gedimino pr. 1', '01103', 'LT-VL'),
                channelName: 'Knygų namai',
            ),
            self::CUSTOMER_URL,
        );

        self::assertSame(25.99, $payload['amount']);
        self::assertSame('000123-45', $payload['order_reference']);
        self::assertSame(self::CUSTOMER_URL, $payload['customer_url']);
        self::assertSame('lt', $payload['locale']);
        self::assertSame('client@example.com', $payload['email']);
        self::assertSame('203.0.113.7', $payload['customer_ip']);
        self::assertSame('LT', $payload['preferred_country']);
        // Diacritics are outside the SEPA statement charset and get transliterated.
        self::assertSame('Knygu namai (000123)', $payload['payment_description']);
        self::assertSame('Kaunas', $payload['billing_city']);
        self::assertSame('LT', $payload['billing_country']);
        self::assertSame('Savanorių pr. 1', $payload['billing_line1']);
        self::assertSame('44255', $payload['billing_postcode']);
        self::assertSame('LT-KU', $payload['billing_state']);
        self::assertSame('Vilnius', $payload['shipping_city']);
        self::assertSame('LT-VL', $payload['shipping_state']);

        $integration = $payload['integration_details'];
        self::assertIsArray($integration);
        self::assertSame('pkglt/sylius-everypay-plugin', $integration['integration']);
        self::assertSame('Sylius', $integration['software']);
        // Version comes from package metadata (env-dependent) - assert presence, not value.
        self::assertIsString($integration['version']);
        self::assertNotSame('', $integration['version']);
    }

    public function testUnknownLocaleFallsBackToEnglishAndNonBalticCountryIsNotPreferred(): void
    {
        $factory = new EveryPayOneOffPayloadFactory($this->requestStackWithClientIp(null));

        $payload = $factory->create(
            $this->payment(
                amount: 1000,
                paymentId: 1,
                orderNumber: '000009',
                localeCode: 'ja_JP',
                email: null,
                billingAddress: $this->address('Berlin', 'DE', 'Unter den Linden 1', '10117'),
                shippingAddress: null,
            ),
            self::CUSTOMER_URL,
        );

        self::assertSame('en', $payload['locale']);
        self::assertArrayNotHasKey('preferred_country', $payload);
        self::assertArrayNotHasKey('email', $payload);
        self::assertArrayNotHasKey('customer_ip', $payload);
        self::assertArrayNotHasKey('shipping_city', $payload);
        // No province on the address, so no state field is sent.
        self::assertArrayNotHasKey('billing_state', $payload);
        self::assertSame('Berlin', $payload['billing_city']);
        // No channel stubbed: the description degrades to the order number.
        self::assertSame('(000009)', $payload['payment_description']);
    }

    public function testPaymentDescriptionTransliteratesDiacriticsAndStripsTheRest(): void
    {
        $factory = new EveryPayOneOffPayloadFactory($this->requestStackWithClientIp(null));

        $payload = $factory->create(
            $this->payment(
                amount: 1000,
                paymentId: 1,
                orderNumber: '000042',
                localeCode: 'lt_LT',
                email: null,
                billingAddress: null,
                shippingAddress: null,
                // Baltic diacritics transliterate to their ASCII base; the
                // ampersand has no transliteration and is stripped, with the
                // leftover whitespace collapsed.
                channelName: 'Žąsų ūkis & Māja Öö',
            ),
            self::CUSTOMER_URL,
        );

        self::assertSame('Zasu ukis Maja Oo (000042)', $payload['payment_description']);
    }

    public function testPaymentDescriptionCapTrimsTheChannelNameAndKeepsTheOrderNumber(): void
    {
        $factory = new EveryPayOneOffPayloadFactory($this->requestStackWithClientIp(null));

        $payload = $factory->create(
            $this->payment(
                amount: 1000,
                paymentId: 1,
                orderNumber: '000010',
                localeCode: 'lt_LT',
                email: null,
                billingAddress: null,
                shippingAddress: null,
                channelName: str_repeat('Very long shop name ', 10),
            ),
            self::CUSTOMER_URL,
        );

        $description = $payload['payment_description'];
        self::assertIsString($description);
        self::assertLessThanOrEqual(65, strlen($description));
        self::assertStringEndsWith('(000010)', $description);
        self::assertMatchesRegularExpression("#^[a-zA-Z0-9/?:().,'+ -]+$#", $description);
    }

    public function testOrderReferenceIsSanitizedAndCappedForCustomOrderNumbers(): void
    {
        $factory = new EveryPayOneOffPayloadFactory($this->requestStackWithClientIp(null));

        $payload = $factory->create(
            $this->payment(
                amount: 1000,
                paymentId: 7,
                // Diacritics transliterate, the hash and spaces are outside
                // the order_reference charset and get stripped.
                orderNumber: 'UŽS #123 (web)',
                localeCode: 'lt_LT',
                email: null,
                billingAddress: null,
                shippingAddress: null,
            ),
            self::CUSTOMER_URL,
        );

        self::assertSame('UZS123(web)-7', $payload['order_reference']);

        $payload = $factory->create(
            $this->payment(
                amount: 1000,
                paymentId: 12345,
                orderNumber: str_repeat('9', 150),
                localeCode: 'lt_LT',
                email: null,
                billingAddress: null,
                shippingAddress: null,
            ),
            self::CUSTOMER_URL,
        );

        // Capped under the 120-char Open Banking limit, payment-id suffix intact.
        self::assertSame(str_repeat('9', 100) . '-12345', $payload['order_reference']);
    }

    public function testOverLongAddressFieldsAreFittedToTheCharacterLimits(): void
    {
        $factory = new EveryPayOneOffPayloadFactory($this->requestStackWithClientIp(null));

        $payload = $factory->create(
            $this->payment(
                amount: 1000,
                paymentId: 1,
                orderNumber: '000011',
                localeCode: 'lt_LT',
                email: null,
                // Multibyte repeats prove the limits count characters, not bytes.
                billingAddress: $this->address(str_repeat('Ž', 60), 'LT', str_repeat('ą', 55), str_repeat('9', 20), 'LT-KU'),
                shippingAddress: $this->address(str_repeat('Ū', 51), 'LT', 'Gedimino pr. 1', '01103'),
            ),
            self::CUSTOMER_URL,
        );

        // A single over-long word has no boundary to cut at.
        self::assertSame(str_repeat('Ž', 50), $payload['billing_city']);
        self::assertSame(str_repeat('ą', 50), $payload['billing_line1']);
        // A cut postcode would be a wrong one - it is left out instead.
        self::assertArrayNotHasKey('billing_postcode', $payload);
        self::assertSame('LT-KU', $payload['billing_state']);
        self::assertSame(str_repeat('Ū', 50), $payload['shipping_city']);
        self::assertSame('Gedimino pr. 1', $payload['shipping_line1']);
        self::assertSame('01103', $payload['shipping_postcode']);
    }

    public function testOverLongAddressTextIsShortenedAtAWordBoundary(): void
    {
        $factory = new EveryPayOneOffPayloadFactory($this->requestStackWithClientIp(null));

        $payload = $factory->create(
            $this->payment(
                amount: 1000,
                paymentId: 1,
                orderNumber: '000012',
                localeCode: 'lt_LT',
                email: null,
                billingAddress: $this->address(
                    'Rietavo savivaldybės Daugėdų kaimo bendruomenės centras',
                    'LT',
                    "Laisvės alėja 101-12,\n Kauno miesto savivaldybė, Lietuvos Respublika",
                    '44255',
                ),
                shippingAddress: $this->address('Kaunas', 'LT', str_repeat('a', 50) . ' tail', '44255'),
            ),
            self::CUSTOMER_URL,
        );

        // Street and house number lead and survive; whitespace runs collapse
        // and the dangling separator goes.
        self::assertSame('Laisvės alėja 101-12, Kauno miesto savivaldybė', $payload['billing_line1']);
        self::assertSame('Rietavo savivaldybės Daugėdų kaimo bendruomenės', $payload['billing_city']);
        // A space right after the limit means the hard cut is already a word boundary.
        self::assertSame(str_repeat('a', 50), $payload['shipping_line1']);
    }

    public function testShippingFieldsAreLeftOutWhenNothingShips(): void
    {
        $factory = new EveryPayOneOffPayloadFactory($this->requestStackWithClientIp(null));

        $payload = $factory->create(
            $this->payment(
                amount: 1000,
                paymentId: 1,
                orderNumber: '000013',
                localeCode: 'lt_LT',
                email: null,
                billingAddress: $this->address('Kaunas', 'LT', 'Savanorių pr. 1', '44255'),
                shippingAddress: $this->address('Kaunas', 'LT', 'Savanorių pr. 1', '44255'),
                shippingRequired: false,
            ),
            self::CUSTOMER_URL,
        );

        self::assertSame('Savanorių pr. 1', $payload['billing_line1']);
        self::assertSame([], array_filter(array_keys($payload), static fn (string $key): bool => str_starts_with($key, 'shipping_')));
    }

    public function testSendsTheBillingPhoneNumberSplitForEveryPay(): void
    {
        $factory = new EveryPayOneOffPayloadFactory($this->requestStackWithClientIp(null));

        $payload = $factory->create(
            $this->payment(
                amount: 1000,
                paymentId: 1,
                orderNumber: '000014',
                localeCode: 'lt_LT',
                email: null,
                // National notation, read with the billing country's rules.
                billingAddress: $this->address('Kaunas', 'LT', 'Savanorių pr. 1', '44255', phoneNumber: '8 612 34567'),
                shippingAddress: null,
                customerPhone: '+371 2123 4567',
            ),
            self::CUSTOMER_URL,
        );

        // The checkout's billing phone wins over the customer profile's.
        self::assertSame(['country_code' => '370', 'number' => '61234567'], $payload['phone_number']);
    }

    public function testFallsBackToTheCustomerPhoneNumber(): void
    {
        $factory = new EveryPayOneOffPayloadFactory($this->requestStackWithClientIp(null));

        $payload = $factory->create(
            $this->payment(
                amount: 1000,
                paymentId: 1,
                orderNumber: '000015',
                localeCode: 'lv_LV',
                email: null,
                billingAddress: $this->address('Rīga', 'LV', 'Brīvības iela 1', 'LV-1010', phoneNumber: ' '),
                shippingAddress: null,
                customerPhone: '2123 4567',
            ),
            self::CUSTOMER_URL,
        );

        self::assertSame(['country_code' => '371', 'number' => '21234567'], $payload['phone_number']);
    }

    public function testLeavesOutAPhoneNumberItCannotSplit(): void
    {
        $factory = new EveryPayOneOffPayloadFactory($this->requestStackWithClientIp(null));

        $payload = $factory->create(
            $this->payment(
                amount: 1000,
                paymentId: 1,
                orderNumber: '000016',
                localeCode: 'en_US',
                email: null,
                billingAddress: $this->address('New York', 'US', '5th Avenue 1', '10001', phoneNumber: '(212) 555-0100'),
                shippingAddress: null,
            ),
            self::CUSTOMER_URL,
        );

        self::assertArrayNotHasKey('phone_number', $payload);
    }

    private function requestStackWithClientIp(?string $ip): RequestStack
    {
        $requestStack = new RequestStack();
        if (null !== $ip) {
            $requestStack->push(Request::create('https://shop.example/pay/hash', server: ['REMOTE_ADDR' => $ip]));
        }

        return $requestStack;
    }

    private function payment(
        int $amount,
        int $paymentId,
        string $orderNumber,
        string $localeCode,
        ?string $email,
        ?AddressInterface $billingAddress,
        ?AddressInterface $shippingAddress,
        ?string $channelName = null,
        bool $shippingRequired = true,
        ?string $customerPhone = null,
    ): PaymentInterface {
        $channel = null;
        if (null !== $channelName) {
            $channel = $this->createStub(ChannelInterface::class);
            $channel->method('getName')->willReturn($channelName);
        }

        $customer = null;
        if (null !== $email || null !== $customerPhone) {
            $customer = $this->createStub(CustomerInterface::class);
            $customer->method('getEmail')->willReturn($email);
            $customer->method('getPhoneNumber')->willReturn($customerPhone);
        }

        $order = $this->createStub(OrderInterface::class);
        $order->method('getNumber')->willReturn($orderNumber);
        $order->method('getLocaleCode')->willReturn($localeCode);
        $order->method('getCustomer')->willReturn($customer);
        $order->method('getBillingAddress')->willReturn($billingAddress);
        $order->method('getShippingAddress')->willReturn($shippingAddress);
        $order->method('isShippingRequired')->willReturn($shippingRequired);
        $order->method('getChannel')->willReturn($channel);

        $payment = $this->createStub(PaymentInterface::class);
        $payment->method('getAmount')->willReturn($amount);
        $payment->method('getId')->willReturn($paymentId);
        $payment->method('getOrder')->willReturn($order);

        return $payment;
    }

    private function address(string $city, string $countryCode, string $street, string $postcode, ?string $provinceCode = null, ?string $phoneNumber = null): AddressInterface
    {
        $address = $this->createStub(AddressInterface::class);
        $address->method('getCity')->willReturn($city);
        $address->method('getCountryCode')->willReturn($countryCode);
        $address->method('getStreet')->willReturn($street);
        $address->method('getPostcode')->willReturn($postcode);
        $address->method('getProvinceCode')->willReturn($provinceCode);
        $address->method('getPhoneNumber')->willReturn($phoneNumber);

        return $address;
    }
}
