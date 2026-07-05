<?php

declare(strict_types=1);

namespace Tests\Pkg\SyliusEveryPayPlugin\Functional;

use Pkg\SyliusEveryPayPlugin\Command\CaptureEveryPayPayment;
use Pkg\SyliusEveryPayPlugin\EveryPayGateway;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\Factory\PaymentRequestFactoryInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\RouterInterface;

/**
 * Dispatches the capture command on the real sylius.payment_request.command_bus
 * — through the doctrine transaction middleware, the pessimistic lock and the
 * after-pay URL provider — against the mocked EveryPay API.
 */
final class CapturePaymentRequestTest extends FunctionalTestCase
{
    private const PAYMENT_REFERENCE = 'abc123def456abc123def456abc123def456abc123def456abc123def456abcd';

    private const PAYMENT_LINK = 'https://igw-demo.every-pay.com/lp/x419d7/3HeCGV01';

    public function testSuccessfulCaptureStoresTheHostedPageLinkAndKeepsThePaymentRetryable(): void
    {
        static::bootKernel();
        $this->prepareDatabase();
        $paymentRequest = $this->createCapturePaymentRequest();

        $this->everyPayHttpMock()->queueJson([
            'payment_reference' => self::PAYMENT_REFERENCE,
            'payment_link' => self::PAYMENT_LINK,
            'payment_state' => 'initial',
            'order_reference' => '000000001-1',
            'account_name' => 'EUR3D1',
            'currency' => 'EUR',
        ], 201);

        $this->commandBus()->dispatch(new CaptureEveryPayPayment($paymentRequest->getId()));

        $payment = EveryPayGateway::corePaymentFrom($paymentRequest);
        self::assertSame(PaymentRequestInterface::STATE_PROCESSING, $paymentRequest->getState());
        self::assertSame(self::PAYMENT_LINK, $paymentRequest->getResponseData()['payment_link'] ?? null);
        self::assertSame(self::PAYMENT_REFERENCE, EveryPayGateway::paymentReferenceFrom($payment->getDetails()));
        // Deliberately still `new`: the customer must be able to re-enter the pay flow.
        self::assertSame(PaymentInterface::STATE_NEW, $payment->getState());

        // The oneoff request carried the after-pay return URL of the real shop route.
        $requests = $this->everyPayHttpMock()->recordedRequests();
        self::assertCount(1, $requests);
        self::assertSame('POST', $requests[0]['method']);
        self::assertStringContainsString('/v4/payments/oneoff', $requests[0]['url']);
        $customerUrl = $this->customerUrlFrom((string) $requests[0]['body']);
        self::assertStringContainsString('/order/after-pay/', $customerUrl);
        self::assertStringContainsString('"payment_description":"Web order 000000001"', (string) $requests[0]['body']);
    }

    public function testAnExplicitPayloadUrlIsSentAsTheCustomerUrl(): void
    {
        static::bootKernel();
        $this->prepareDatabase();
        $paymentRequest = $this->createCapturePaymentRequest();
        $paymentRequest->setPayload(['after_pay_url' => 'https://spa.example/checkout/thank-you']);
        $this->entityManager()->flush();

        $this->everyPayHttpMock()->queueJson([
            'payment_reference' => self::PAYMENT_REFERENCE,
            'payment_link' => self::PAYMENT_LINK,
            'payment_state' => 'initial',
        ], 201);

        $this->commandBus()->dispatch(new CaptureEveryPayPayment($paymentRequest->getId()));

        $requests = $this->everyPayHttpMock()->recordedRequests();
        self::assertCount(1, $requests);
        self::assertSame('https://spa.example/checkout/thank-you', $this->customerUrlFrom((string) $requests[0]['body']));
    }

    public function testApiFailureFailsPaymentRequestAndPaymentWithoutThrowing(): void
    {
        static::bootKernel();
        $this->prepareDatabase();
        $paymentRequest = $this->createCapturePaymentRequest();

        $this->everyPayHttpMock()->queueJson([
            'error' => ['code' => 4001, 'message' => 'Authentication failed'],
        ], 401);

        $this->commandBus()->dispatch(new CaptureEveryPayPayment($paymentRequest->getId()));

        $payment = EveryPayGateway::corePaymentFrom($paymentRequest);
        self::assertSame(PaymentRequestInterface::STATE_FAILED, $paymentRequest->getState());
        self::assertSame(PaymentInterface::STATE_FAILED, $payment->getState());
        self::assertArrayHasKey('error', $paymentRequest->getResponseData());
    }

    private function createCapturePaymentRequest(): PaymentRequestInterface
    {
        $channel = $this->createShopEnvironment();
        $method = $this->createEveryPayPaymentMethod($channel);
        $payment = $this->createOrderWithPayment($channel, $method);

        /** @var PaymentRequestFactoryInterface<PaymentRequestInterface> $factory */
        $factory = static::getContainer()->get('sylius.factory.payment_request');
        $paymentRequest = $factory->create($payment, $method);
        $paymentRequest->setAction(PaymentRequestInterface::ACTION_CAPTURE);

        $entityManager = $this->entityManager();
        $entityManager->persist($paymentRequest);
        $entityManager->flush();

        self::assertNotNull($paymentRequest->getId(), 'The payment request has no hash after persisting.');

        // In the shop the after-pay URL is generated during an HTTP request;
        // a bus dispatch from the CLI context has no _locale router parameter
        // (older Sylius versions do not derive it from the order).
        $this->service(RouterInterface::class, 'router')->getContext()->setParameter('_locale', 'en_US');

        return $paymentRequest;
    }

    private function customerUrlFrom(string $requestBody): string
    {
        /** @var array<string, mixed> $body */
        $body = json_decode($requestBody, true, 512, \JSON_THROW_ON_ERROR);
        $customerUrl = $body['customer_url'] ?? null;
        if (!is_string($customerUrl)) {
            self::fail('The oneoff request body carries no customer_url.');
        }

        return $customerUrl;
    }

    private function commandBus(): MessageBusInterface
    {
        return $this->service(MessageBusInterface::class, 'sylius.payment_request.command_bus');
    }
}
