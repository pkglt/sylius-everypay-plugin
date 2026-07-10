<?php

declare(strict_types=1);

namespace Tests\Pkg\SyliusEveryPayPlugin\Unit\CommandHandler;

use PHPUnit\Framework\TestCase;
use Pkg\SyliusEveryPayPlugin\Client\EveryPayApiClient;
use Pkg\SyliusEveryPayPlugin\Command\RefundEveryPayPayment;
use Pkg\SyliusEveryPayPlugin\CommandHandler\RefundEveryPayPaymentHandler;
use Pkg\SyliusEveryPayPlugin\EveryPayGateway;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Model\PaymentRequest;
use Sylius\Component\Payment\PaymentRequestTransitions;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class RefundEveryPayPaymentHandlerTest extends TestCase
{
    private const PAYMENT_REFERENCE = 'abc123def456abc123def456abc123def456abc123def456abc123def456abcd';

    /** @var array<array{object, string, string}> */
    private array $appliedTransitions = [];

    public function testRefundsTheFullAmountConvertedToDecimal(): void
    {
        $capturedBody = null;
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedBody): MockResponse {
            $capturedBody = json_decode((string) $options['body'], true);

            return new MockResponse(json_encode(['payment_state' => 'refunded'], \JSON_THROW_ON_ERROR), ['http_code' => 201]);
        });

        $paymentRequest = $this->paymentRequest(amountInCents: 2599);
        $handler = $this->handler($paymentRequest, $httpClient);

        $handler(new RefundEveryPayPayment('hash'));

        self::assertIsArray($capturedBody);
        self::assertSame(25.99, $capturedBody['amount']);
        self::assertSame(self::PAYMENT_REFERENCE, $capturedBody['payment_reference']);

        $payment = $paymentRequest->getPayment();
        self::assertInstanceOf(Payment::class, $payment);
        self::assertSame('refunded', EveryPayGateway::detailsFrom($payment->getDetails())['payment_state']);
        self::assertSame(['payment_state' => 'refunded'], $paymentRequest->getResponseData());
        self::assertSame(
            [[$paymentRequest, PaymentRequestTransitions::GRAPH, PaymentRequestTransitions::TRANSITION_COMPLETE]],
            $this->appliedTransitions,
        );
    }

    public function testMissingPaymentReferenceAbortsBeforeAnyApiCall(): void
    {
        $httpClient = new MockHttpClient(function (): MockResponse {
            self::fail('No API call expected without an EveryPay payment reference.');
        });

        $paymentRequest = $this->paymentRequest(amountInCents: 2599, withReference: false);
        $handler = $this->handler($paymentRequest, $httpClient);

        $this->expectException(\LogicException::class);

        $handler(new RefundEveryPayPayment('hash'));
    }

    private function paymentRequest(int $amountInCents, bool $withReference = true): PaymentRequest
    {
        $gatewayConfig = $this->createStub(GatewayConfigInterface::class);
        $gatewayConfig->method('getConfig')->willReturn([
            EveryPayGateway::CONFIG_API_USERNAME => 'a04e7ce1060e7024',
            EveryPayGateway::CONFIG_API_SECRET => 'secret',
            EveryPayGateway::CONFIG_ACCOUNT_NAME => 'EUR3D1',
            EveryPayGateway::CONFIG_ENVIRONMENT => EveryPayGateway::ENVIRONMENT_DEMO,
        ]);

        $method = $this->createStub(PaymentMethodInterface::class);
        $method->method('getGatewayConfig')->willReturn($gatewayConfig);

        $payment = new Payment();
        $payment->setAmount($amountInCents);
        $payment->setMethod($method);
        if ($withReference) {
            $payment->setDetails([
                EveryPayGateway::DETAILS_KEY => ['payment_reference' => self::PAYMENT_REFERENCE],
            ]);
        }

        return new PaymentRequest($payment, $method);
    }

    private function handler(PaymentRequest $paymentRequest, HttpClientInterface $httpClient): RefundEveryPayPaymentHandler
    {
        $paymentRequestProvider = $this->createStub(PaymentRequestProviderInterface::class);
        $paymentRequestProvider->method('provide')->willReturn($paymentRequest);

        $this->appliedTransitions = [];
        $stateMachine = $this->createStub(StateMachineInterface::class);
        $stateMachine->method('apply')->willReturnCallback(
            function (object $subject, string $graph, string $transition): void {
                $this->appliedTransitions[] = [$subject, $graph, $transition];
            },
        );

        return new RefundEveryPayPaymentHandler(
            $paymentRequestProvider,
            new EveryPayApiClient($httpClient),
            $stateMachine,
        );
    }
}
