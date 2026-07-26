<?php

declare(strict_types=1);

namespace Firefly\Observability\Metrics;

/** A timer recorded as a summary: a call count and a total time in seconds. */
final class Timer implements Meter
{
    private int $count = 0;

    private float $totalSeconds = 0.0;

    /** @param array<string, string> $tags */
    public function __construct(
        private readonly string $name,
        private readonly array $tags,
    ) {}

    public function record(float $seconds): void
    {
        $this->count++;
        $this->totalSeconds += $seconds;
    }

    public function count(): int
    {
        return $this->count;
    }

    public function totalTimeSeconds(): float
    {
        return $this->totalSeconds;
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
        return MeterType::Timer;
    }
}
