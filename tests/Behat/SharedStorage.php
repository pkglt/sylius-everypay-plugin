<?php

declare(strict_types=1);

namespace Tests\Pkg\SyliusEveryPayPlugin\Behat;

/**
 * Minimal scenario-scoped storage shared between Behat contexts (the kernel -
 * and therefore this service - is rebuilt for every scenario).
 */
final class SharedStorage
{
    /** @var array<string, object> */
    private array $items = [];

    public function set(string $key, object $value): void
    {
        $this->items[$key] = $value;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $type
     *
     * @return T
     */
    public function get(string $key, string $type): object
    {
        $item = $this->items[$key] ?? throw new \LogicException(sprintf('Nothing stored under "%s" - is a setup step missing?', $key));
        if (!$item instanceof $type) {
            throw new \LogicException(sprintf('Stored "%s" is a %s, expected %s.', $key, $item::class, $type));
        }

        return $item;
    }
}
