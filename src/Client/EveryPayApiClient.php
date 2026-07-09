<?php

declare(strict_types=1);

namespace Pkg\SyliusEveryPayPlugin\Client;

use DateTimeImmutable;
use DateTimeInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Talks to the EveryPay Payment API v4 (see docs/everypay-api.md
 * and the saved OpenAPI spec). All endpoints use HTTP Basic auth; every body
 * carries api_username, a unique nonce and an ISO 8601 timestamp (+/-5 min
 * server-time window).
 */
final class EveryPayApiClient
{
    private const REQUEST_TIMEOUT = 30;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    /**
     * POST /v4/payments/oneoff - creates a payment and returns (among others)
     * `payment_reference` and the hosted payment page `payment_link`.
     *
     * @param array<string, mixed> $payload business fields (amount, order_reference, customer_url, ...)
     *
     * @return array<string, mixed>
     */
    public function createOneOffPayment(EveryPayCredentials $credentials, array $payload): array
    {
        return $this->request($credentials, 'POST', '/v4/payments/oneoff', array_merge(
            $this->defaultBodyParameters($credentials),
            ['account_name' => $credentials->accountName],
            $payload,
        ));
    }

    /**
     * GET /v4/payments/{payment_reference} - the authoritative payment state.
     * Callbacks and customer returns are unauthenticated hints; this call is
     * the only source of truth.
     *
     * @return array<string, mixed>
     */
    public function getPayment(EveryPayCredentials $credentials, string $paymentReference): array
    {
        $path = sprintf(
            '/v4/payments/%s?api_username=%s',
            rawurlencode($paymentReference),
            rawurlencode($credentials->apiUsername),
        );

        return $this->request($credentials, 'GET', $path);
    }

    /**
     * GET /v4/processing_accounts/{account_name} - the cheapest authenticated
     * call there is; used to verify admin-entered credentials. A short
     * timeout keeps the admin form responsive.
     *
     * @return array<string, mixed>
     */
    public function getProcessingAccount(EveryPayCredentials $credentials, ?float $timeout = null): array
    {
        $path = sprintf(
            '/v4/processing_accounts/%s?api_username=%s',
            rawurlencode($credentials->accountName),
            rawurlencode($credentials->apiUsername),
        );

        return $this->request($credentials, 'GET', $path, timeout: $timeout);
    }

    /**
     * POST /v4/payments/refund - full or partial refund of a settled payment.
     *
     * @return array<string, mixed>
     */
    public function refundPayment(EveryPayCredentials $credentials, string $paymentReference, float $amount): array
    {
        return $this->request($credentials, 'POST', '/v4/payments/refund', array_merge(
            $this->defaultBodyParameters($credentials),
            [
                'payment_reference' => $paymentReference,
                'amount' => $amount,
            ],
        ));
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>
     */
    private function request(EveryPayCredentials $credentials, string $method, string $path, ?array $body = null, ?float $timeout = null): array
    {
        $options = [
            'auth_basic' => [$credentials->apiUsername, $credentials->apiSecret],
            'timeout' => $timeout ?? self::REQUEST_TIMEOUT,
        ];
        if ($body !== null) {
            $options['json'] = $body;
        }

        try {
            $response = $this->httpClient->request($method, $credentials->baseUrl . $path, $options);
            $statusCode = $response->getStatusCode();
            $content = $response->getContent(false);
        } catch (HttpClientExceptionInterface $e) {
            throw new EveryPayApiException(sprintf('EveryPay request %s %s failed: %s', $method, $path, $e->getMessage()), previous: $e);
        }

        $data = json_decode($content, true);

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new EveryPayApiException(sprintf(
                'EveryPay responded HTTP %d to %s %s: %s',
                $statusCode,
                $method,
                $path,
                $this->extractErrorMessage($data, $content),
            ), $statusCode);
        }

        if (!is_array($data)) {
            throw new EveryPayApiException(sprintf('EveryPay returned a non-JSON body to %s %s: %s', $method, $path, $content), $statusCode);
        }

        /** @var array<string, mixed> $decoded */
        $decoded = $data;

        return $decoded;
    }

    private function extractErrorMessage(mixed $data, string $fallback): string
    {
        if (is_array($data)) {
            $error = $data['error'] ?? null;
            if (is_array($error) && is_string($error['message'] ?? null)) {
                return $error['message'];
            }
        }

        return $fallback;
    }

    /**
     * @return array<string, string>
     */
    private function defaultBodyParameters(EveryPayCredentials $credentials): array
    {
        return [
            'api_username' => $credentials->apiUsername,
            'nonce' => bin2hex(random_bytes(16)),
            'timestamp' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
        ];
    }
}
