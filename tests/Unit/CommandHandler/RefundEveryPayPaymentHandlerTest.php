<?php

declare(strict_types=1);

namespace Tests\Pkg\SyliusEveryPayPlugin\Unit\CommandHandler;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pkg\SyliusEveryPayPlugin\Client\EveryPayApiClient;
use Pkg\SyliusEveryPayPlugin\Client\EveryPayApiException;
use Pkg\SyliusEveryPayPlugin\Command\RefundEveryPayPayment;
use Pkg\SyliusEveryPayPlugin\CommandHandler\RefundEveryPayPaymentHandler;
use Pkg\SyliusEveryPayPlugin\EveryPayGateway;
use Psr\Log\NullLogger;
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

    /** @var list<array{method: string, url: string}> */
    private array $recordedRequests = [];

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

    #[DataProvider('reconcilableRejectionCodes')]
    public function testReconcilesARejectedRefundWhenEveryPayReportsRefunded(int $rejectionCode): void
    {
        $httpClient = $this->scriptedClient([
            self::jsonResponse(['error' => ['code' => 4000, 'message' => 'Refund amount exceeds the standing amount']], $rejectionCode),
            self::jsonResponse([
                'payment_reference' => self::PAYMENT_REFERENCE,
                'payment_state' => 'refunded',
                'payment_method' => 'card',
                'standing_amount' => 0,
                'payment_created_at' => '2026-08-05T10:00:00Z',
            ]),
        ]);

        $paymentRequest = $this->paymentRequest(amountInCents: 2599);
        $handler = $this->handler($paymentRequest, $httpClient);

        $handler(new RefundEveryPayPayment('hash'));

        self::assertCount(2, $this->recordedRequests);
        self::assertSame('GET', $this->recordedRequests[1]['method']);
        self::assertStringContainsString('/v4/payments/' . self::PAYMENT_REFERENCE, $this->recordedRequests[1]['url']);

        $payment = $paymentRequest->getPayment();
        self::assertInstanceOf(Payment::class, $payment);
        $details = EveryPayGateway::detailsFrom($payment->getDetails());
        self::assertSame('refunded', $details['payment_state']);
        self::assertSame(0, $details['standing_amount']);
        self::assertSame(self::PAYMENT_REFERENCE, $details['payment_reference']);
        self::assertSame(['payment_state' => 'refunded'], $paymentRequest->getResponseData());
        self::assertSame(
            [[$paymentRequest, PaymentRequestTransitions::GRAPH, PaymentRequestTransitions::TRANSITION_COMPLETE]],
            $this->appliedTransitions,
        );
    }

    /** @return iterable<string, array{int}> */
    public static function reconcilableRejectionCodes(): iterable
    {
        yield 'bad request' => [400];
        yield 'not found' => [404];
        yield 'unprocessable entity' => [422];
    }

    #[DataProvider('nonRefundedRemoteStates')]
    public function testRethrowsTheRejectionWhenEveryPayReportsANonRefundedState(string $remoteState): void
    {
        $httpClient = $this->scriptedClient([
            self::jsonResponse(['error' => ['code' => 4000, 'message' => 'Invalid refund request']], 422),
            self::jsonResponse([
                'payment_reference' => self::PAYMENT_REFERENCE,
                'payment_state' => $remoteState,
                'standing_amount' => 25.99,
            ]),
        ]);

        $paymentRequest = $this->paymentRequest(amountInCents: 2599);
        $handler = $this->handler($paymentRequest, $httpClient);

        try {
            $handler(new RefundEveryPayPayment('hash'));
            self::fail('The rejected refund was unexpectedly reconciled.');
        } catch (EveryPayApiException $exception) {
            self::assertSame(422, $exception->statusCode);
        }

        self::assertCount(2, $this->recordedRequests);
        self::assertSame([], $this->appliedTransitions);

        $payment = $paymentRequest->getPayment();
        self::assertInstanceOf(Payment::class, $payment);
        self::assertArrayNotHasKey('payment_state', EveryPayGateway::detailsFrom($payment->getDetails()));
    }

    /** @return iterable<string, array{string}> */
    public static function nonRefundedRemoteStates(): iterable
    {
        yield 'still settled' => ['settled'];
        yield 'never committed' => ['initial'];
        yield 'charged back' => ['charged_back'];
    }

    #[DataProvider('nonReconcilableFailures')]
    public function testDoesNotAttemptReconciliationForServerOrTransportFailures(MockResponse $refundResponse, int $expectedStatusCode): void
    {
        $httpClient = $this->scriptedClient([$refundResponse]);

        $paymentRequest = $this->paymentRequest(amountInCents: 2599);
        $handler = $this->handler($paymentRequest, $httpClient);

        try {
            $handler(new RefundEveryPayPayment('hash'));
            self::fail('The failed refund was unexpectedly reconciled.');
        } catch (EveryPayApiException $exception) {
            self::assertSame($expectedStatusCode, $exception->statusCode);
        }

        self::assertCount(1, $this->recordedRequests);
        self::assertSame([], $this->appliedTransitions);
    }

    /** @return iterable<string, array{MockResponse, int}> */
    public static function nonReconcilableFailures(): iterable
    {
        yield 'server error' => [self::jsonResponse(['error' => ['code' => 5000, 'message' => 'Processing error']], 500), 500];
        yield 'transport failure' => [new MockResponse('', ['error' => 'Connection refused']), 0];
    }

    public function testRethrowsTheOriginalRejectionWhenTheStateReadFails(): void
    {
        $httpClient = $this->scriptedClient([
            self::jsonResponse(['error' => ['code' => 4000, 'message' => 'Invalid refund request']], 422),
            self::jsonResponse(['error' => ['code' => 5000, 'message' => 'Processing error']], 500),
        ]);

        $paymentRequest = $this->paymentRequest(amountInCents: 2599);
        $handler = $this->handler($paymentRequest, $httpClient);

        try {
            $handler(new RefundEveryPayPayment('hash'));
            self::fail('The rejected refund was unexpectedly reconciled.');
        } catch (EveryPayApiException $exception) {
            self::assertSame(422, $exception->statusCode);
        }

        self::assertCount(2, $this->recordedRequests);
        self::assertSame([], $this->appliedTransitions);
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function jsonResponse(array $body, int $statusCode = 200): MockResponse
    {
        return new MockResponse(json_encode($body, \JSON_THROW_ON_ERROR), ['http_code' => $statusCode]);
    }

    /**
     * @param list<MockResponse> $responses
     */
    private function scriptedClient(array $responses): MockHttpClient
    {
        $this->recordedRequests = [];

        return new MockHttpClient(function (string $method, string $url) use (&$responses): MockResponse {
            $this->recordedRequests[] = ['method' => $method, 'url' => $url];
            if ([] === $responses) {
                self::fail(sprintf('Unexpected EveryPay API request "%s %s".', $method, $url));
            }

            return array_shift($responses);
        });
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
            new NullLogger(),
        );
    }
}
