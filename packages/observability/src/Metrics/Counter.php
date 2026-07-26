<?php

declare(strict_types=1);

namespace Firefly\Observability\Metrics;

/** A monotonic counter. */
final class Counter implements Meter
{
    private float $value = 0.0;

    /** @param array<string, string> $tags */
    public function __construct(
        private readonly string $name,
        private readonly array $tags,
    ) {}

    public function increment(float $amount = 1.0): void
    {
        $this->value += $amount;
    }

    public function count(): float
    {
        return $this->value;
    }

    public function name(): string
    {
        return $this->name;
    }

    /** @return array<string, string> */
    public function tags(): array
    {
        return $this->tags;
    }

    public function type(): MeterType
    {
        return MeterType::Counter;
    }
}
