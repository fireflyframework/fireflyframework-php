<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Tests\CapstoneFixtures\Ledger;

/** Counts handler invocations, so a capstone can tell a cache hit (no call) from a miss (one call). */
final class HandlerCalls
{
    /** @var array<string, int> */
    public static array $counts = [];

    public static function hit(string $handler): void
    {
        self::$counts[$handler] = (self::$counts[$handler] ?? 0) + 1;
    }

    public static function of(string $handler): int
    {
        return self::$counts[$handler] ?? 0;
    }

    public static function reset(): void
    {
        self::$counts = [];
    }
}
