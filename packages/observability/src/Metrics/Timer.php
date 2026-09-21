<?php

declare(strict_types=1);

namespace Firefly\Observability\Metrics;

/**
 * A timer: a call count and a total time in seconds — and, when its name is configured with buckets, a
 * Prometheus-style cumulative histogram over fixed upper bounds. Buckets are cumulative (a sample lands in
 * every bucket whose bound it does not exceed, `le` = less than or equal) and `+Inf` is implied by count(),
 * exactly as the exposition format defines them, so PrometheusTextFormat can render the family verbatim.
 *
 * fromAggregates() exists for CacheMeterRegistry, which stores count, total and per-bucket counts as
 * integers in the cache and needs to rebuild a Timer without replaying samples (replaying would put every
 * sample in the same bucket, which is what the old "one sample per call at the average" rehydration would
 * have done to a histogram).
 */
final class Timer implements Meter
{
    private int $count = 0;

    private float $totalSeconds = 0.0;

    /** @var list<int> cumulative count per bound, index-aligned with the buckets list */
    private array $bucketCounts;

    /**
     * @param  array<string, string>  $tags
     * @param  list<float>  $buckets  ascending upper bounds in seconds; [] for a plain summary
     */
    public function __construct(
        private readonly string $name,
        private readonly array $tags,
        private readonly array $buckets = [],
    ) {
        $this->bucketCounts = array_map(static fn (): int => 0, $buckets);
    }

    /**
     * @param  array<string, string>  $tags
     * @param  list<float>  $buckets
     * @param  list<int>  $bucketCounts  cumulative, aligned with $buckets
     */
    public static function fromAggregates(string $name, array $tags, array $buckets, int $count, float $totalSeconds, array $bucketCounts): self
    {
        $timer = new self($name, $tags, $buckets);
        $timer->count = $count;
        $timer->totalSeconds = $totalSeconds;

        $counts = [];
        foreach (array_keys($buckets) as $index) {
            $counts[] = $bucketCounts[$index] ?? 0;
        }
        $timer->bucketCounts = $counts;

        return $timer;
    }

    public function record(float $seconds): void
    {
        $this->count++;
        $this->totalSeconds += $seconds;

        // Rebuilt as a whole (array_map over two aligned lists) rather than incremented by index, which is
        // the same work and keeps the property a proper list for static analysis.
        $this->bucketCounts = array_map(
            static fn (float $bound, int $count): int => $seconds <= $bound ? $count + 1 : $count,
            $this->buckets,
            $this->bucketCounts,
        );
    }

    public function count(): int
    {
        return $this->count;
    }

    public function totalTimeSeconds(): float
    {
        return $this->totalSeconds;
    }

    /** @return list<float> */
    public function buckets(): array
    {
        return $this->buckets;
    }

    public function hasBuckets(): bool
    {
        return $this->buckets !== [];
    }

    /** @return list<array{le: float, count: int}> cumulative, ascending; +Inf is count() */
    public function bucketCounts(): array
    {
        $rows = [];
        foreach ($this->buckets as $index => $bound) {
            $rows[] = ['le' => $bound, 'count' => $this->bucketCounts[$index]];
        }

        return $rows;
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
