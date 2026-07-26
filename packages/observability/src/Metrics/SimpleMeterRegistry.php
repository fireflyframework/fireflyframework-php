<?php

declare(strict_types=1);

namespace Firefly\Observability\Metrics;

/**
 * The first-party in-memory registry (no ext, no OTel). Idempotent registration keyed by type|name|sorted-tags, so
 * repeated counter()/timer()/gauge() calls with the same identity return the SAME meter instance. Implements both
 * the read-facing MeterRegistry and the write-facing MetricsRecorder; the recorder methods delegate to the factory
 * methods (setGauge stores a value and registers a supplier gauge over it).
 */
final class SimpleMeterRegistry implements MeterRegistry, MetricsRecorder
{
    /** @var array<string, Meter> */
    private array $meters = [];

    /** @var array<string, float> backing store for setGauge() values, keyed like the meter */
    private array $gaugeValues = [];

    /** @param array<string, string> $tags */
    public function counter(string $name, array $tags = []): Counter
    {
        $key = $this->key(MeterType::Counter, $name, $tags);
        $meter = $this->meters[$key] ?? null;
        if (! $meter instanceof Counter) {
            $meter = new Counter($name, $this->sort($tags));
            $this->meters[$key] = $meter;
        }

        return $meter;
    }

    /** @param array<string, string> $tags */
    public function timer(string $name, array $tags = []): Timer
    {
        $key = $this->key(MeterType::Timer, $name, $tags);
        $meter = $this->meters[$key] ?? null;
        if (! $meter instanceof Timer) {
            $meter = new Timer($name, $this->sort($tags));
            $this->meters[$key] = $meter;
        }

        return $meter;
    }

    /**
     * @param  array<string, string>  $tags
     * @param  callable(): float  $supplier
     */
    public function gauge(string $name, array $tags, callable $supplier): Gauge
    {
        $key = $this->key(MeterType::Gauge, $name, $tags);
        $meter = $this->meters[$key] ?? null;
        if (! $meter instanceof Gauge) {
            $meter = new Gauge($name, $this->sort($tags), $supplier);
            $this->meters[$key] = $meter;
        }

        return $meter;
    }

    /** @return list<Meter> */
    public function meters(): array
    {
        return array_values($this->meters);
    }

    /** @param array<string, string> $tags */
    public function increment(string $name, array $tags = [], float $amount = 1.0): void
    {
        $this->counter($name, $tags)->increment($amount);
    }

    /** @param array<string, string> $tags */
    public function record(string $name, array $tags = [], float $seconds = 0.0): void
    {
        $this->timer($name, $tags)->record($seconds);
    }

    /** @param array<string, string> $tags */
    public function setGauge(string $name, array $tags, float $value): void
    {
        $key = $this->key(MeterType::Gauge, $name, $tags);
        $this->gaugeValues[$key] = $value;
        if (! isset($this->meters[$key])) {
            $this->meters[$key] = new Gauge($name, $this->sort($tags), fn (): float => $this->gaugeValues[$key]);
        }
    }

    /** @param array<string, string> $tags */
    private function key(MeterType $type, string $name, array $tags): string
    {
        return $type->value.'|'.$name.'|'.serialize($this->sort($tags));
    }

    /**
     * @param  array<string, string>  $tags
     * @return array<string, string>
     */
    private function sort(array $tags): array
    {
        ksort($tags);

        return $tags;
    }
}
