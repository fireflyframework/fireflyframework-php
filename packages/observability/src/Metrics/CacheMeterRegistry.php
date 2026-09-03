<?php

declare(strict_types=1);

namespace Firefly\Observability\Metrics;

use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * A MeterRegistry whose counters and timers survive the request that recorded them.
 *
 * SimpleMeterRegistry keeps everything in process memory. That is correct for a long-lived worker, but PHP's
 * usual deployment is not one: under PHP-FPM every request gets a fresh process, so by the time a scrape hits
 * /actuator/metrics or /actuator/prometheus the only meters in memory are the ones that request itself
 * recorded. The endpoints were therefore effectively empty in production, and the numbers they did show were
 * a single request's — which is worse than empty, because it looks like data.
 *
 * This registry writes through to the application cache so a scrape sees what every worker recorded:
 *
 *   - increment() and record() use the store's ATOMIC increment, so concurrent workers cannot lose writes on
 *     a driver that supports it (redis, memcached, apc, dynamodb). Durations accumulate in MICROSECONDS as
 *     integers, because increment() is integer-only and float read-modify-write would drop samples.
 *   - setGauge() is a plain put(): a gauge is a snapshot, so last-writer-wins is the correct semantic.
 *   - meters() rehydrates Counter/Timer/Gauge objects from an index of every meter identity ever written, so
 *     the exposition and the /metrics endpoint read the cross-process totals.
 *
 * The factory methods (counter()/timer()/gauge()) still hand back the in-process meters, and mutating one of
 * those directly stays process-local — that is the documented boundary. Everything the framework itself
 * records goes through the MetricsRecorder methods, which are the durable path.
 *
 * Not wired by default: ObservabilityAutoConfiguration binds SimpleMeterRegistry unless
 * `firefly.observability.metrics.store` names a cache store, because a metrics registry that silently starts
 * writing to whatever cache an application configured is a surprise, and on the `array` driver it would be
 * no better than memory anyway.
 */
final class CacheMeterRegistry implements MeterRegistry, MetricsRecorder
{
    private const INDEX = 'index';

    /** Durations are stored as integer microseconds so the atomic increment can be used. */
    private const MICROS = 1_000_000;

    private readonly SimpleMeterRegistry $local;

    public function __construct(
        private readonly Cache $cache,
        private readonly string $prefix = 'firefly:metrics:',
        private readonly ?int $ttlSeconds = null,
    ) {
        $this->local = new SimpleMeterRegistry;
    }

    /** @param array<string, string> $tags */
    public function counter(string $name, array $tags = []): Counter
    {
        return $this->local->counter($name, $tags);
    }

    /** @param array<string, string> $tags */
    public function timer(string $name, array $tags = []): Timer
    {
        return $this->local->timer($name, $tags);
    }

    /**
     * @param  array<string, string>  $tags
     * @param  callable(): float  $supplier
     */
    public function gauge(string $name, array $tags, callable $supplier): Gauge
    {
        return $this->local->gauge($name, $tags, $supplier);
    }

    /** @param array<string, string> $tags */
    public function increment(string $name, array $tags = [], float $amount = 1.0): void
    {
        $this->local->increment($name, $tags, $amount);

        $id = $this->identity(MeterType::Counter, $name, $tags);
        $this->remember($id, MeterType::Counter, $name, $tags);
        $this->add($id.':count', (int) round($amount * self::MICROS));
    }

    /** @param array<string, string> $tags */
    public function record(string $name, array $tags = [], float $seconds = 0.0): void
    {
        $this->local->record($name, $tags, $seconds);

        $id = $this->identity(MeterType::Timer, $name, $tags);
        $this->remember($id, MeterType::Timer, $name, $tags);
        $this->add($id.':count', 1);
        $this->add($id.':micros', (int) round($seconds * self::MICROS));
    }

    /** @param array<string, string> $tags */
    public function setGauge(string $name, array $tags, float $value): void
    {
        $this->local->setGauge($name, $tags, $value);

        $id = $this->identity(MeterType::Gauge, $name, $tags);
        $this->remember($id, MeterType::Gauge, $name, $tags);
        $this->put($id.':value', $value);
    }

    /**
     * Every meter any worker has recorded, rebuilt from the store.
     *
     * @return list<Meter>
     */
    public function meters(): array
    {
        $meters = [];

        foreach ($this->index() as $id => $entry) {
            $type = MeterType::tryFrom($entry['type']);
            if ($type === null) {
                continue;
            }

            $meters[] = match ($type) {
                MeterType::Counter => $this->rebuildCounter($id, $entry),
                MeterType::Timer => $this->rebuildTimer($id, $entry),
                MeterType::Gauge => $this->rebuildGauge($id, $entry),
            };
        }

        return $meters;
    }

    /** @param array{type: string, name: string, tags: array<string,string>} $entry */
    private function rebuildCounter(string $id, array $entry): Counter
    {
        $counter = new Counter($entry['name'], $entry['tags']);
        $counter->increment($this->readInt($id.':count') / self::MICROS);

        return $counter;
    }

    /** @param array{type: string, name: string, tags: array<string,string>} $entry */
    private function rebuildTimer(string $id, array $entry): Timer
    {
        $timer = new Timer($entry['name'], $entry['tags']);
        $count = $this->readInt($id.':count');
        $total = $this->readInt($id.':micros') / self::MICROS;

        // Timer accumulates per-sample; replay the total as one sample per recorded call so both count()
        // and totalTimeSeconds() come back right. The per-sample values are not retained by design — this
        // registry stores aggregates, not a histogram.
        if ($count > 0) {
            $each = $total / $count;
            for ($i = 0; $i < $count; $i++) {
                $timer->record($each);
            }
        }

        return $timer;
    }

    /** @param array{type: string, name: string, tags: array<string,string>} $entry */
    private function rebuildGauge(string $id, array $entry): Gauge
    {
        $value = $this->cache->get($this->prefix.$id.':value');

        return new Gauge($entry['name'], $entry['tags'], static fn (): float => is_numeric($value) ? (float) $value : 0.0);
    }

    /**
     * The identities of every meter written so far. Kept as one small array so meters() needs a single read
     * rather than a key scan, which not every cache driver supports.
     *
     * @return array<string, array{type: string, name: string, tags: array<string,string>}>
     */
    private function index(): array
    {
        $index = $this->cache->get($this->prefix.self::INDEX);

        if (! is_array($index)) {
            return [];
        }

        /** @var array<string, array{type: string, name: string, tags: array<string,string>}> $index */
        return $index;
    }

    /** @param array<string, string> $tags */
    private function remember(string $id, MeterType $type, string $name, array $tags): void
    {
        $index = $this->index();
        if (isset($index[$id])) {
            return;
        }

        ksort($tags);
        $index[$id] = ['type' => $type->value, 'name' => $name, 'tags' => $tags];
        $this->put(self::INDEX, $index);
    }

    private function add(string $key, int $amount): void
    {
        $full = $this->prefix.$key;

        // increment() returns false on a store that has no value yet (and on drivers that cannot increment a
        // missing key), so seed and retry rather than losing the sample.
        if ($this->cache->increment($full, $amount) === false) {
            $this->cache->add($full, 0, $this->ttlSeconds);
            $this->cache->increment($full, $amount);
        }
    }

    private function put(string $key, mixed $value): void
    {
        $full = $this->prefix.$key;

        if ($this->ttlSeconds === null) {
            $this->cache->forever($full, $value);

            return;
        }

        $this->cache->put($full, $value, $this->ttlSeconds);
    }

    private function readInt(string $key): int
    {
        $value = $this->cache->get($this->prefix.$key);

        return is_numeric($value) ? (int) $value : 0;
    }

    /** @param array<string, string> $tags */
    private function identity(MeterType $type, string $name, array $tags): string
    {
        ksort($tags);

        return $type->value.'|'.$name.'|'.md5(serialize($tags));
    }
}
