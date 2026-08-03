<?php

declare(strict_types=1);

namespace Tests\Pkg\SyliusEveryPayPlugin\Unit\Provider;

use PHPUnit\Framework\TestCase;
use Pkg\SyliusEveryPayPlugin\EveryPayGateway;
use Pkg\SyliusEveryPayPlugin\Provider\PaymentElementsViewFactory;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Model\PaymentRequest;

final class PaymentElementsViewFactoryTest extends TestCase
{
    private const PAYMENT_LINK = 'https://igw-demo.every-pay.com/lp/x419d7/3HeCGV01';

    private const RESPONSE_DATA = [
        'payment_link' => self::PAYMENT_LINK,
        'payment_reference' => 'abc123def456',
        'payment_elements' => [
            'mobile_access_token' => 'mobile-access-token-123',
            'customer_url' => 'https://shop.example/after-pay/hash',
            'order_reference' => '000123-7',
            'amount' => 25.99,
            'locale' => 'lt',
            'email' => 'customer@example.com',
            'preferred_country' => 'LT',
        ],
    ];

    public function testShapesTheSdkSetupAndThePaymentIntent(): void
    {
        $view = (new PaymentElementsViewFactory())->viewFrom($this->paymentRequest());

        self::assertNotNull($view);
        self::assertSame(self::PAYMENT_LINK, $view['payment_link']);
        self::assertSame(EveryPayGateway::ELEMENTS_SDK_URLS[EveryPayGateway::ENVIRONMENT_DEMO], $view['sdk_url']);
        self::assertSame([
            'setup' => [
                'account_name' => 'EUR3D1',
                'api_username' => 'a04e7ce1060e7024',
                'environment' => 'demo',
                'amount' => 25.99,
                'locale' => 'lt',
                'email' => 'customer@example.com',
                'preferred_country' => 'LT',
            ],
            'payment_intent' => [
                'accountName' => 'EUR3D1',
                'apiUsername' => 'a04e7ce1060e7024',
                'bearerToken' => 'mobile-access-token-123',
                'orderReference' => '000123-7',
                'paymentLink' => self::PAYMENT_LINK,
                'returnURL' => 'https://shop.example/after-pay/hash',
                'paymentReference' => 'abc123def456',
            ],
        ], $view['elements']);
    }

    public function testTheLiveEnvironmentUsesTheProductionSdk(): void
    {
        $view = (new PaymentElementsViewFactory())->viewFrom(
            $this->paymentRequest(environment: EveryPayGateway::ENVIRONMENT_LIVE),
        );

        self::assertNotNull($view);
        self::assertSame(EveryPayGateway::ELEMENTS_SDK_URLS[EveryPayGateway::ENVIRONMENT_LIVE], $view['sdk_url']);
        $elements = $view['elements'];
        self::assertIsArray($elements);
        self::assertSame('production', $elements['setup']['environment'] ?? null);
    }

    public function testFallsBackToPaymentAmountAndEnglishWhenTheBlobIsSparse(): void
    {
        $responseData = self::RESPONSE_DATA;
        unset(
            $responseData['payment_elements']['amount'],
            $responseData['payment_elements']['locale'],
            $responseData['payment_elements']['email'],
            $responseData['payment_elements']['preferred_country'],
        );

        $view = (new PaymentElementsViewFactory())->viewFrom($this->paymentRequest($responseData));

        self::assertNotNull($view);
        $elements = $view['elements'];
        self::assertIsArray($elements);
        $setup = $elements['setup'];
        self::assertIsArray($setup);
        self::assertSame(25.99, $setup['amount']);
        self::assertSame('en', $setup['locale']);
        self::assertNull($setup['email']);
        self::assertNull($setup['preferred_country']);
    }

    public function testReturnsNullWithoutTheElementsBlob(): void
    {
        $responseData = self::RESPONSE_DATA;
        unset($responseData['payment_elements']);

        self::assertNull((new PaymentElementsViewFactory())->viewFrom($this->paymentRequest($responseData)));
    }

    public function testReturnsNullWhenAConfirmFieldIsMissing(): void
    {
        foreach (['mobile_access_token', 'customer_url', 'order_reference'] as $field) {
            $responseData = self::RESPONSE_DATA;
            unset($responseData['payment_elements'][$field]);

            self::assertNull(
                (new PaymentElementsViewFactory())->viewFrom($this->paymentRequest($responseData)),
                sprintf('Expected no view without "%s" - the SDK cannot confirm() without it.', $field),
            );
        }
    }

    public function testReturnsNullWhenTheGatewayConfigLacksCredentials(): void
    {
        self::assertNull(
            (new PaymentElementsViewFactory())->viewFrom($this->paymentRequest(apiUsername: '')),
        );
    }

    /**
     * @param array<string, mixed>|null $responseData
     */
    private function paymentRequest(
        ?array $responseData = null,
        string $environment = EveryPayGateway::ENVIRONMENT_DEMO,
        string $apiUsername = 'a04e7ce1060e7024',
    ): PaymentRequest {
        $gatewayConfig = $this->createStub(GatewayConfigInterface::class);
        $gatewayConfig->method('getConfig')->willReturn([
            EveryPayGateway::CONFIG_API_USERNAME => $apiUsername,
            EveryPayGateway::CONFIG_ACCOUNT_NAME => 'EUR3D1',
            EveryPayGateway::CONFIG_ENVIRONMENT => $environment,
        ]);

        $method = $this->createStub(PaymentMethodInterface::class);
        $method->method('getGatewayConfig')->willReturn($gatewayConfig);

        $payment = new Payment();
        $payment->setAmount(2599);

        $paymentRequest = new PaymentRequest($payment, $method);
        $paymentRequest->setResponseData($responseData ?? self::RESPONSE_DATA);

        return $paymentRequest;
    }
}
