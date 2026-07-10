<?php

declare(strict_types=1);

namespace Tests\Pkg\SyliusEveryPayPlugin\Unit\Provider;

use PHPUnit\Framework\TestCase;
use Pkg\SyliusEveryPayPlugin\EveryPayGateway;
use Pkg\SyliusEveryPayPlugin\Provider\EveryPayHttpResponseProvider;
use Sylius\Bundle\ResourceBundle\Controller\RequestConfiguration;
use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Model\PaymentRequest;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Twig\Environment;

final class EveryPayHttpResponseProviderTest extends TestCase
{
    private const PAYMENT_LINK = 'https://igw-demo.every-pay.com/lp/x419d7/3HeCGV01';

    public function testRedirectsToTheHostedPageByDefault(): void
    {
        $provider = $this->provider();
        $paymentRequest = $this->paymentRequest();

        self::assertTrue($provider->supports($this->requestConfiguration(), $paymentRequest));

        $response = $provider->getResponse($this->requestConfiguration(), $paymentRequest);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame(303, $response->getStatusCode());
        self::assertSame(self::PAYMENT_LINK, $response->getTargetUrl());
    }

    public function testFallsBackToRedirectWhenTheGridHasNoPerMethodLinks(): void
    {
        $twig = $this->createMock(Environment::class);
        $twig->expects(self::never())->method('render');
        $provider = new EveryPayHttpResponseProvider($twig);

        // Grid configured, but EveryPay returned no per-method links: the
        // hosted page redirect must stay as the fallback.
        $paymentRequest = $this->paymentRequest(
            displayMode: EveryPayGateway::DISPLAY_MODE_METHOD_GRID,
            responseData: ['payment_link' => self::PAYMENT_LINK, 'payment_methods' => []],
        );

        $response = $provider->getResponse($this->requestConfiguration(), $paymentRequest);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame(self::PAYMENT_LINK, $response->getTargetUrl());
    }

    public function testRendersTheMethodGridGroupedAroundTheBillingCountry(): void
    {
        $twig = $this->createMock(Environment::class);
        $twig
            ->expects(self::once())
            ->method('render')
            ->with(
                '@PkgSyliusEveryPayPlugin/shop/method_grid.html.twig',
                self::callback(static function (array $context): bool {
                    self::assertSame(self::PAYMENT_LINK, $context['payment_link']);

                    $groups = $context['method_groups'];
                    self::assertIsArray($groups);
                    $firstGroup = $groups[0] ?? null;
                    self::assertIsArray($firstGroup);
                    self::assertSame('LT', $firstGroup['country_code']);

                    return true;
                }),
            )
            ->willReturn('<grid>');
        $provider = new EveryPayHttpResponseProvider($twig);

        $paymentRequest = $this->paymentRequest(
            displayMode: EveryPayGateway::DISPLAY_MODE_METHOD_GRID,
            responseData: [
                'payment_link' => self::PAYMENT_LINK,
                'payment_methods' => [
                    ['source' => 'card', 'display_name' => 'Card', 'country_code' => null, 'payment_link' => 'https://pay.example/card', 'logo_url' => null],
                    ['source' => 'seb_ob_lt', 'display_name' => 'SEB', 'country_code' => 'LT', 'payment_link' => 'https://pay.example/seb', 'logo_url' => null],
                ],
            ],
            billingCountry: 'LT',
        );

        $response = $provider->getResponse($this->requestConfiguration(), $paymentRequest);

        self::assertSame('<grid>', $response->getContent());
    }

    public function testDoesNotSupportOtherActionsThanCapture(): void
    {
        $paymentRequest = $this->paymentRequest();
        $paymentRequest->setAction(PaymentRequestInterface::ACTION_STATUS);

        self::assertFalse($this->provider()->supports($this->requestConfiguration(), $paymentRequest));
    }

    public function testDoesNotSupportRequestsThatAreNotProcessing(): void
    {
        $paymentRequest = $this->paymentRequest();
        $paymentRequest->setState(PaymentRequestInterface::STATE_NEW);

        self::assertFalse($this->provider()->supports($this->requestConfiguration(), $paymentRequest));
    }

    public function testDoesNotSupportPaymentsAlreadyInAFinalState(): void
    {
        $paymentRequest = $this->paymentRequest(paymentState: PaymentInterface::STATE_COMPLETED);

        self::assertFalse($this->provider()->supports($this->requestConfiguration(), $paymentRequest));
    }

    public function testDoesNotSupportRequestsWithoutAPaymentLink(): void
    {
        $paymentRequest = $this->paymentRequest(responseData: []);

        self::assertFalse($this->provider()->supports($this->requestConfiguration(), $paymentRequest));
    }

    private function provider(): EveryPayHttpResponseProvider
    {
        return new EveryPayHttpResponseProvider($this->createMock(Environment::class));
    }

    private function requestConfiguration(): RequestConfiguration
    {
        return $this->createStub(RequestConfiguration::class);
    }

    /**
     * @param array<string, mixed>|null $responseData
     */
    private function paymentRequest(
        string $displayMode = EveryPayGateway::DISPLAY_MODE_REDIRECT,
        ?array $responseData = null,
        string $paymentState = PaymentInterface::STATE_NEW,
        ?string $billingCountry = null,
    ): PaymentRequest {
        $gatewayConfig = $this->createStub(GatewayConfigInterface::class);
        $gatewayConfig->method('getConfig')->willReturn([
            EveryPayGateway::CONFIG_DISPLAY_MODE => $displayMode,
        ]);

        $method = $this->createStub(PaymentMethodInterface::class);
        $method->method('getGatewayConfig')->willReturn($gatewayConfig);

        $payment = new Payment();
        $payment->setState($paymentState);
        if (null !== $billingCountry) {
            $address = $this->createStub(AddressInterface::class);
            $address->method('getCountryCode')->willReturn($billingCountry);
            $order = $this->createStub(OrderInterface::class);
            $order->method('getBillingAddress')->willReturn($address);
            $payment->setOrder($order);
        }

        $paymentRequest = new PaymentRequest($payment, $method);
        $paymentRequest->setState(PaymentRequestInterface::STATE_PROCESSING);
        $paymentRequest->setResponseData($responseData ?? ['payment_link' => self::PAYMENT_LINK]);

        return $paymentRequest;
    }
}
