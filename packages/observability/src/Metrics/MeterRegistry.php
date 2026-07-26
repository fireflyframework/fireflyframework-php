<?php

declare(strict_types=1);

namespace Firefly\Observability\Metrics;

/** The meter store the exposition + /metrics endpoint read. Factory methods are idempotent per name + tag set. */
interface MeterRegistry
{
    /** @param array<string, string> $tags */
    public function counter(string $name, array $tags = []): Counter;

    /** @param array<string, string> $tags */
    public function timer(string $name, array $tags = []): Timer;

    /**
     * @param  array<string, string>  $tags
     * @param  callable(): float  $supplier
     */
    public function gauge(string $name, array $tags, callable $supplier): Gauge;

    /** @return list<Meter> */
    public function meters(): array;
}
