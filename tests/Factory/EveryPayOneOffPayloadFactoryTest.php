<?php

declare(strict_types=1);

namespace Tests\Pkg\SyliusEveryPayPlugin\Factory;

use PHPUnit\Framework\TestCase;
use Pkg\SyliusEveryPayPlugin\Factory\EveryPayOneOffPayloadFactory;
use Sylius\Component\Core\Model\AddressInterface;
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
                billingAddress: $this->address('Kaunas', 'LT', 'Savanorių pr. 1', '44255'),
                shippingAddress: $this->address('Vilnius', 'LT', 'Gedimino pr. 1', '01103'),
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
        self::assertSame('Kaunas', $payload['billing_city']);
        self::assertSame('LT', $payload['billing_country']);
        self::assertSame('Savanorių pr. 1', $payload['billing_line1']);
        self::assertSame('44255', $payload['billing_postcode']);
        self::assertSame('Vilnius', $payload['shipping_city']);
        self::assertSame(
            ['integration' => 'custom', 'software' => 'Sylius', 'version' => '2.2'],
            $payload['integration_details'],
        );
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
        self::assertSame('Berlin', $payload['billing_city']);
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
    ): PaymentInterface {
        $customer = null;
        if (null !== $email) {
            $customer = $this->createStub(CustomerInterface::class);
            $customer->method('getEmail')->willReturn($email);
        }

        $order = $this->createStub(OrderInterface::class);
        $order->method('getNumber')->willReturn($orderNumber);
        $order->method('getLocaleCode')->willReturn($localeCode);
        $order->method('getCustomer')->willReturn($customer);
        $order->method('getBillingAddress')->willReturn($billingAddress);
        $order->method('getShippingAddress')->willReturn($shippingAddress);

        $payment = $this->createStub(PaymentInterface::class);
        $payment->method('getAmount')->willReturn($amount);
        $payment->method('getId')->willReturn($paymentId);
        $payment->method('getOrder')->willReturn($order);

        return $payment;
    }

    private function address(string $city, string $countryCode, string $street, string $postcode): AddressInterface
    {
        $address = $this->createStub(AddressInterface::class);
        $address->method('getCity')->willReturn($city);
        $address->method('getCountryCode')->willReturn($countryCode);
        $address->method('getStreet')->willReturn($street);
        $address->method('getPostcode')->willReturn($postcode);

        return $address;
    }
}
