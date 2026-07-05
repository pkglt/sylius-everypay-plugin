<?php

declare(strict_types=1);

namespace Tests\Pkg\SyliusEveryPayPlugin\Functional\Support;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Stands in for the EveryPay API in the functional suite: tests queue scripted
 * responses, the real EveryPayApiClient consumes them through production code
 * paths. Every performed request is recorded for assertions.
 */
final class EveryPayHttpMock extends MockHttpClient
{
    /** @var list<MockResponse> */
    private array $queue = [];

    /** @var list<array{method: string, url: string, body: ?string}> */
    private array $recordedRequests = [];

    public function __construct()
    {
        parent::__construct(function (string $method, string $url, array $options): MockResponse {
            $body = $options['body'] ?? null;
            $this->recordedRequests[] = ['method' => $method, 'url' => $url, 'body' => is_string($body) ? $body : null];

            if ([] === $this->queue) {
                throw new \LogicException(sprintf('Unexpected EveryPay API request "%s %s" — no response queued.', $method, $url));
            }

            return array_shift($this->queue);
        });
    }

    public function queueResponse(MockResponse $response): void
    {
        $this->queue[] = $response;
    }

    /**
     * @param array<string, mixed> $body
     */
    public function queueJson(array $body, int $statusCode = 200): void
    {
        $this->queueResponse(new MockResponse(
            json_encode($body, \JSON_THROW_ON_ERROR),
            ['http_code' => $statusCode, 'response_headers' => ['content-type' => 'application/json']],
        ));
    }

    /**
     * @return list<array{method: string, url: string, body: ?string}>
     */
    public function recordedRequests(): array
    {
        return $this->recordedRequests;
    }
}
