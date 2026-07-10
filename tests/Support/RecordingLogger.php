<?php

declare(strict_types=1);

namespace Tests\Pkg\SyliusEveryPayPlugin\Support;

use Psr\Log\AbstractLogger;

/**
 * Collects log records so tests can assert on the operator-facing warnings
 * the plugin promises (currency mismatch, unreachable state, chargebacks).
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string}> */
    private array $records = [];

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->records[] = [
            'level' => is_string($level) ? $level : 'other',
            'message' => (string) $message,
        ];
    }

    /**
     * @return list<string>
     */
    public function messages(string $level): array
    {
        $messages = [];
        foreach ($this->records as $record) {
            if ($level === $record['level']) {
                $messages[] = $record['message'];
            }
        }

        return $messages;
    }
}
