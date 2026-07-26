<?php

declare(strict_types=1);

namespace Firefly\Observability\Metrics;

use InvalidArgumentException;

/**
 * The first-party in-memory registry (no ext, no OTel). Idempotent registration keyed by type|name|sorted-tags, so
 * repeated counter()/timer()/gauge() calls with the same identity return the SAME meter instance. Implements both
 * the read-facing MeterRegistry and the write-facing MetricsRecorder; the recorder methods delegate to the factory
 * methods (setGauge stores a value and registers a supplier gauge over it).
 *
 * A Prometheus metric NAME has exactly one type, globally — so registering the same name under a different
 * MeterType (e.g. counter('foo') then gauge('foo', ...)) is a programming error, not a valid overload: it would
 * make the renderer emit two conflicting `# TYPE foo` lines for one name, which is invalid Prometheus exposition.
 * We fail fast on that instead of silently producing broken output.
 */
final class SimpleMeterRegistry implements MeterRegistry, MetricsRecorder
{
    /** @var array<string, Meter> */
    private array $meters = [];

    /** @var array<string, MeterType> the type each metric NAME was first registered as, regardless of tags */
    private array $namesToTypes = [];

    /** @var array<string, float> backing store for setGauge() values, keyed like the meter */
    private array $gaugeValues = [];

    /** @param array<string, string> $tags */
    public function counter(string $name, array $tags = []): Counter
    {
        $this->guardType($name, MeterType::Counter);
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
        $this->guardType($name, MeterType::Timer);
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
        $this->guardType($name, MeterType::Gauge);
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
        $this->guardType($name, MeterType::Gauge);
        $key = $this->key(MeterType::Gauge, $name, $tags);
        $this->gaugeValues[$key] = $value;
        if (! isset($this->meters[$key])) {
            $this->meters[$key] = new Gauge($name, $this->sort($tags), fn (): float => $this->gaugeValues[$key]);
        }
    }

    /**
     * Fail fast when metric $name is already registered under a DIFFERENT MeterType. The check is by name alone
     * (not name+tags): Prometheus scopes a `# TYPE` declaration to the metric name, so two different tag-sets
     * under the same name must still share one type. Same-name-same-type is the normal idempotent path and is
     * left untouched here — this only rejects a genuine type conflict.
     */
    private function guardType(string $name, MeterType $type): void
    {
        $existing = $this->namesToTypes[$name] ?? null;
        if ($existing !== null && $existing !== $type) {
            throw new InvalidArgumentException(
                "Metric '{$name}' already registered as {$existing->value}; cannot re-register as {$type->value}."
            );
        }
        $this->namesToTypes[$name] = $type;
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
