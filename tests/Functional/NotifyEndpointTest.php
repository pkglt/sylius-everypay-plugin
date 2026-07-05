<?php

declare(strict_types=1);

namespace Tests\Pkg\SyliusEveryPayPlugin\Functional;

use Pkg\SyliusEveryPayPlugin\EveryPayGateway;
use Sylius\Component\Core\Model\PaymentInterface;

/**
 * Drives the real /payment-methods/{code} notify endpoint over HTTP: callback
 * resolution by payment_reference, the side-effect-free error paths, and a
 * full settled callback moving the payment through the state machine after
 * re-verification against the (mocked) EveryPay API.
 */
final class NotifyEndpointTest extends FunctionalTestCase
{
    private const PAYMENT_REFERENCE = 'abc123def456abc123def456abc123def456abc123def456abc123def456abcd';

    public function testMalformedPaymentReferenceIsRejectedWithBadRequest(): void
    {
        $client = static::createClient();
        $this->prepareDatabase();
        $channel = $this->createShopEnvironment();
        $this->createEveryPayPaymentMethod($channel);

        $client->request('POST', '/payment-methods/everypay?payment_reference=not-a-reference');

        self::assertResponseStatusCodeSame(400);
    }

    public function testUnknownPaymentReferenceIsNotFound(): void
    {
        $client = static::createClient();
        $this->prepareDatabase();
        $channel = $this->createShopEnvironment();
        $this->createEveryPayPaymentMethod($channel);

        $client->request('POST', '/payment-methods/everypay?payment_reference=' . self::PAYMENT_REFERENCE);

        self::assertResponseStatusCodeSame(404);
    }

    public function testSettledCallbackCompletesThePaymentViaApiReVerification(): void
    {
        $client = static::createClient();
        $this->prepareDatabase();
        $channel = $this->createShopEnvironment();
        $method = $this->createEveryPayPaymentMethod($channel);
        $payment = $this->createOrderWithPayment($channel, $method, [
            EveryPayGateway::DETAILS_KEY => [
                'payment_reference' => self::PAYMENT_REFERENCE,
                'payment_link' => 'https://igw-demo.every-pay.com/lp/example',
            ],
        ]);

        $this->everyPayHttpMock()->queueJson([
            'payment_reference' => self::PAYMENT_REFERENCE,
            'payment_state' => 'settled',
            'payment_method' => 'card',
            'standing_amount' => 10.0,
        ]);

        $client->request(
            'POST',
            '/payment-methods/everypay?payment_reference=' . self::PAYMENT_REFERENCE . '&event_name=status_updated',
        );

        self::assertResponseIsSuccessful();

        $this->entityManager()->refresh($payment);
        self::assertSame(PaymentInterface::STATE_COMPLETED, $payment->getState());
        self::assertSame('settled', EveryPayGateway::detailsFrom($payment->getDetails())['payment_state'] ?? null);

        // The callback itself was never trusted — the state came from the API.
        $requests = $this->everyPayHttpMock()->recordedRequests();
        self::assertCount(1, $requests);
        self::assertSame('GET', $requests[0]['method']);
        self::assertStringContainsString('/v4/payments/' . self::PAYMENT_REFERENCE, $requests[0]['url']);
    }
}
