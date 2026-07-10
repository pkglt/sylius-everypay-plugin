<?php

declare(strict_types=1);

namespace Tests\Pkg\SyliusEveryPayPlugin\Unit\CommandHandler;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Pkg\SyliusEveryPayPlugin\Client\EveryPayApiClient;
use Pkg\SyliusEveryPayPlugin\Command\CaptureEveryPayPayment;
use Pkg\SyliusEveryPayPlugin\CommandHandler\CaptureEveryPayPaymentHandler;
use Pkg\SyliusEveryPayPlugin\EveryPayGateway;
use Pkg\SyliusEveryPayPlugin\Factory\EveryPayOneOffPayloadFactory;
use Pkg\SyliusEveryPayPlugin\Provider\AfterPayUrlProviderInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Model\PaymentRequest;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\PaymentRequestTransitions;
use Sylius\Component\Payment\PaymentTransitions;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Tests\Pkg\SyliusEveryPayPlugin\Support\RecordingLogger;

final class CaptureEveryPayPaymentHandlerTest extends TestCase
{
    private const PAYMENT_LINK = 'https://igw-demo.every-pay.com/lp/x419d7/3HeCGV01';

    private const PAYMENT_REFERENCE = 'abc123def456abc123def456abc123def456abc123def456abc123def456abcd';

    /** @var array<array{object, string, string}> */
    private array $appliedTransitions = [];

    public function testSuccessfulCaptureStoresLinkAndProcessesOnlyThePaymentRequest(): void
    {
        $paymentRequest = $this->paymentRequest();
        $handler = $this->handler($paymentRequest, new MockResponse(json_encode([
            'payment_reference' => self::PAYMENT_REFERENCE,
            'payment_link' => self::PAYMENT_LINK,
            'payment_state' => 'initial',
        ], \JSON_THROW_ON_ERROR), ['http_code' => 201]));

        $handler(new CaptureEveryPayPayment('hash'));

        $payment = $paymentRequest->getPayment();
        self::assertInstanceOf(Payment::class, $payment);
        self::assertSame(self::PAYMENT_REFERENCE, EveryPayGateway::paymentReferenceFrom($payment->getDetails()));
        self::assertSame(self::PAYMENT_LINK, $paymentRequest->getResponseData()['payment_link']);

        // Exactly one transition: the payment request moves to processing;
        // the payment itself must stay `new` so the pay flow is re-enterable.
        self::assertCount(1, $this->appliedTransitions);
        self::assertSame(
            [$paymentRequest, PaymentRequestTransitions::GRAPH, PaymentRequestTransitions::TRANSITION_PROCESS],
            $this->appliedTransitions[0],
        );
    }

    public function testApiFailureFailsPaymentRequestAndPaymentWithoutThrowing(): void
    {
        $paymentRequest = $this->paymentRequest();
        $handler = $this->handler($paymentRequest, new MockResponse(json_encode([
            'error' => ['code' => 4999, 'message' => '401 Unauthorized'],
        ], \JSON_THROW_ON_ERROR), ['http_code' => 401]));

        $handler(new CaptureEveryPayPayment('hash'));

        self::assertArrayHasKey('error', $paymentRequest->getResponseData());
        self::assertSame(
            [
                [$paymentRequest, PaymentRequestTransitions::GRAPH, PaymentRequestTransitions::TRANSITION_FAIL],
                [$paymentRequest->getPayment(), PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_FAIL],
            ],
            $this->appliedTransitions,
        );
    }

    public function testAlreadyProcessingRequestIsNotCapturedTwice(): void
    {
        $paymentRequest = $this->paymentRequest();
        $paymentRequest->setState(PaymentRequestInterface::STATE_PROCESSING);

        $handler = $this->handler($paymentRequest, new MockResponse(json_encode([], \JSON_THROW_ON_ERROR)));

        $handler(new CaptureEveryPayPayment('hash'));

        self::assertSame([], $this->appliedTransitions);
        self::assertSame([], $paymentRequest->getResponseData());
    }

    public function testResponseWithoutReferenceOrLinkFailsTheAttempt(): void
    {
        $paymentRequest = $this->paymentRequest();
        $handler = $this->handler($paymentRequest, new MockResponse(json_encode([
            'payment_state' => 'initial',
        ], \JSON_THROW_ON_ERROR), ['http_code' => 201]));

        $handler(new CaptureEveryPayPayment('hash'));

        // A 2xx response without the hosted page link is as unusable as an
        // API error: fail the attempt so the customer gets a fresh payment.
        self::assertArrayHasKey('error', $paymentRequest->getResponseData());
        self::assertSame(
            [
                [$paymentRequest, PaymentRequestTransitions::GRAPH, PaymentRequestTransitions::TRANSITION_FAIL],
                [$paymentRequest->getPayment(), PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_FAIL],
            ],
            $this->appliedTransitions,
        );
    }

    public function testWarnsWhenTheProcessingAccountSuggestsAnotherCurrency(): void
    {
        $logger = new RecordingLogger();
        $paymentRequest = $this->paymentRequest(paymentCurrency: 'USD');
        $handler = $this->handler($paymentRequest, new MockResponse(json_encode([
            'payment_reference' => self::PAYMENT_REFERENCE,
            'payment_link' => self::PAYMENT_LINK,
        ], \JSON_THROW_ON_ERROR), ['http_code' => 201]), $logger);

        $handler(new CaptureEveryPayPayment('hash'));

        // EUR3D1 hints EUR, the payment is USD - warn, but never block.
        $warnings = $logger->messages('warning');
        self::assertCount(1, $warnings);
        self::assertStringContainsString('different currency', $warnings[0]);
        self::assertSame(self::PAYMENT_LINK, $paymentRequest->getResponseData()['payment_link']);
    }

    public function testDoesNotWarnWhenTheCurrenciesMatch(): void
    {
        $logger = new RecordingLogger();
        $paymentRequest = $this->paymentRequest(paymentCurrency: 'EUR');
        $handler = $this->handler($paymentRequest, new MockResponse(json_encode([
            'payment_reference' => self::PAYMENT_REFERENCE,
            'payment_link' => self::PAYMENT_LINK,
        ], \JSON_THROW_ON_ERROR), ['http_code' => 201]), $logger);

        $handler(new CaptureEveryPayPayment('hash'));

        self::assertSame([], $logger->messages('warning'));
    }

    private function paymentRequest(?string $paymentCurrency = null): PaymentRequest
    {
        $order = $this->createStub(OrderInterface::class);
        $order->method('getNumber')->willReturn('000123');
        $order->method('getLocaleCode')->willReturn('lt_LT');
        $order->method('getCustomer')->willReturn(null);
        $order->method('getCustomerIp')->willReturn('203.0.113.7');
        $order->method('getBillingAddress')->willReturn(null);
        $order->method('getShippingAddress')->willReturn(null);

        $payment = new Payment();
        $payment->setAmount(2599);
        $payment->setOrder($order);
        if (null !== $paymentCurrency) {
            $payment->setCurrencyCode($paymentCurrency);
        }

        $gatewayConfig = $this->createStub(GatewayConfigInterface::class);
        $gatewayConfig->method('getConfig')->willReturn([
            EveryPayGateway::CONFIG_API_USERNAME => 'a04e7ce1060e7024',
            EveryPayGateway::CONFIG_API_SECRET => 'secret',
            EveryPayGateway::CONFIG_ACCOUNT_NAME => 'EUR3D1',
        ]);

        $method = $this->createStub(PaymentMethodInterface::class);
        $method->method('getGatewayConfig')->willReturn($gatewayConfig);

        return new PaymentRequest($payment, $method);
    }

    private function handler(PaymentRequest $paymentRequest, MockResponse $apiResponse, ?LoggerInterface $logger = null): CaptureEveryPayPaymentHandler
    {
        $paymentRequestProvider = $this->createStub(PaymentRequestProviderInterface::class);
        $paymentRequestProvider->method('provide')->willReturn($paymentRequest);

        $afterPayUrlProvider = $this->createStub(AfterPayUrlProviderInterface::class);
        $afterPayUrlProvider->method('getUrl')->willReturn('https://shop.example/after-pay/hash');

        $this->appliedTransitions = [];
        $stateMachine = $this->createStub(StateMachineInterface::class);
        $stateMachine->method('can')->willReturn(true);
        $stateMachine->method('apply')->willReturnCallback(
            function (object $subject, string $graph, string $transition): void {
                $this->appliedTransitions[] = [$subject, $graph, $transition];
            },
        );

        return new CaptureEveryPayPaymentHandler(
            $paymentRequestProvider,
            new EveryPayApiClient(new MockHttpClient($apiResponse)),
            new EveryPayOneOffPayloadFactory(new RequestStack()),
            $afterPayUrlProvider,
            $stateMachine,
            $this->createStub(EntityManagerInterface::class),
            $logger ?? new NullLogger(),
        );
    }
}
