<?php

declare(strict_types=1);

namespace Pkg\SyliusEveryPayPlugin\Client;

use RuntimeException;

final class EveryPayApiException extends RuntimeException
{
    /** @param int $statusCode HTTP status of the EveryPay response, 0 for transport-level failures */
    public function __construct(
        string $message,
        public readonly int $statusCode = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
