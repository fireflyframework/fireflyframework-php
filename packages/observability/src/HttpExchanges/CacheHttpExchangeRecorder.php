<?php

declare(strict_types=1);

namespace Firefly\Observability\HttpExchanges;

use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * A rolling exchange buffer that survives the process that recorded it.
 *
 * THE DEFECT THIS EXISTS FOR is the one CacheMeterRegistry was written to fix, in its sharper form. Under PHP-FPM
 * each request is a fresh process, so an in-memory ring is created empty, receives exactly the one exchange the
 * current request produced, and is thrown away. The process that later renders /actuator/httpexchanges is a
 * different one holding a different empty ring — and its own request has not been recorded yet, because
 * HttpExchangeFilter records on the way OUT. The endpoint therefore reports zero exchanges on every call, in
 * every deployment that is not a long-lived worker. Naming a store in
 * `firefly.observability.httpexchanges.store` swaps this class in and the buffer becomes what it claims to be:
 * the last N exchanges served by the whole application, whichever worker served them.
 *
 * THE LAYOUT: one small integer under `<prefix>seq`, and `capacity` independent rows under `<prefix>slot:<n>`.
 *
 *  - A writer takes a slot by ATOMICALLY incrementing `seq` and using `seq % capacity`. Two workers finishing a
 *    request in the same microsecond therefore get two DIFFERENT slots and both rows survive. The obvious
 *    alternative — keeping the whole ring in one cache key — is a read-modify-write, and under concurrency it
 *    loses every exchange but the last writer's. That is the same reason CacheMeterRegistry counts through
 *    increment() rather than get-add-put.
 *  - Each row stores its own `seq` alongside the exchange, so exchanges() can sort newest-first from the stored
 *    ordinal instead of trusting the slot index (which wraps) or the timestamp (which is only as monotonic as
 *    the clocks of the machines writing it — under a load balancer, not very).
 *  - Rows are stored as PLAIN ARRAYS, never serialized HttpExchange objects. A rolling deploy in which two
 *    versions of this class are live at once, or a cache retained across an upgrade, must not be able to fatal a
 *    worker on `__PHP_Incomplete_Class`; HttpExchange::fromArray() validates and returns null for anything it
 *    does not recognise, and the row is skipped.
 *
 * KNOWN, BOUNDED IMPRECISIONS — stated because a rolling buffer that pretends to be a transaction log is worse
 * than one that admits what it is:
 *
 *  1. A writer that stalls between taking `seq` and writing its row can be overtaken by a writer capacity
 *     ordinals later targeting the same slot, and would then overwrite a NEWER row with an older one. The
 *     stale-write guard below reads the slot first and declines to overwrite a higher `seq`, which closes the
 *     window in practice; it is a guard, not a lock, and the residual failure is one row out of order in the
 *     list — never a crash and never a lost newer row that matters.
 *  2. Lowering `capacity` between deploys orphans the slots above the new capacity: they stop being read (and
 *     expire on their own if a ttl is configured). Raising it makes the extra slots read as empty until traffic
 *     fills them. Neither corrupts anything.
 *  3. The store is shared. Two applications pointed at the same Redis database with the same prefix will
 *     interleave their exchanges. That is true of every cache-backed collector in this package, and the prefix
 *     is constructor-injected so it can be made unique.
 */
final class CacheHttpExchangeRecorder implements HttpExchangeRecorder
{
    private const SEQ = 'seq';

    private const SLOT = 'slot:';

    private readonly int $capacity;

    public function __construct(
        private readonly Cache $cache,
        private readonly string $storeName,
        int $capacity = HttpExchangeCapacity::DEFAULT,
        private readonly string $prefix = 'firefly:httpexchanges:',
        private readonly ?int $ttlSeconds = null,
    ) {
        $this->capacity = HttpExchangeCapacity::clamp($capacity);
    }

    public function record(HttpExchange $exchange): void
    {
        $seq = $this->nextSequence();
        $key = $this->prefix.self::SLOT.($seq % $this->capacity);

        // Stale-write guard (imprecision 1 above): never let a straggler overwrite a row that a later writer
        // already put in this slot.
        $existing = $this->cache->get($key);
        if (is_array($existing) && is_int($existing['seq'] ?? null) && $existing['seq'] > $seq) {
            return;
        }

        $this->put($key, ['seq' => $seq, 'exchange' => $exchange->toArray()]);
    }

    /**
     * Every buffered exchange, newest first, read in ONE multi-get rather than `capacity` round trips.
     *
     * getMultiple() is the PSR-16 method Illuminate\Contracts\Cache\Repository inherits, and Illuminate's
     * implementation routes it to the store's native many()/MGET — so a 100-slot ring costs one Redis command,
     * not 100. That is the whole reason the slot keys are enumerable by index instead of being hashed: a layout
     * whose keys cannot be listed would force a key scan, which several drivers do not support at all.
     *
     * @return list<HttpExchange>
     */
    public function exchanges(): array
    {
        $keys = [];
        for ($slot = 0; $slot < $this->capacity; $slot++) {
            $keys[] = $this->prefix.self::SLOT.$slot;
        }

        /** @var list<array{seq: int, exchange: HttpExchange}> $rows */
        $rows = [];

        foreach ($this->cache->getMultiple($keys) as $value) {
            if (! is_array($value)) {
                continue;
            }

            $seq = $value['seq'] ?? null;
            $payload = $value['exchange'] ?? null;
            if (! is_int($seq) || ! is_array($payload)) {
                continue;
            }

            $exchange = HttpExchange::fromArray($payload);
            if ($exchange === null) {
                continue;
            }

            $rows[] = ['seq' => $seq, 'exchange' => $exchange];
        }

        usort($rows, static fn (array $a, array $b): int => $b['seq'] <=> $a['seq']);

        return array_map(static fn (array $row): HttpExchange => $row['exchange'], $rows);
    }

    public function capacity(): int
    {
        return $this->capacity;
    }

    public function recorded(): int
    {
        $value = $this->cache->get($this->prefix.self::SEQ);

        return is_numeric($value) ? (int) $value : 0;
    }

    public function storage(): string
    {
        return 'cache:'.$this->storeName;
    }

    public function processLocal(): bool
    {
        return false;
    }

    /**
     * The atomic slot allocator.
     *
     * increment() returns false on a store that has no value under the key yet (and on drivers that cannot
     * increment a missing key), so the counter is seeded and the increment retried — the same seed-and-retry
     * CacheMeterRegistry::add() uses, for the same reason: losing the sample is not an option.
     *
     * The final fallback is the millisecond clock, and it is deliberate rather than defensive noise. If a store
     * genuinely cannot increment (a custom driver, or a key holding a non-numeric value someone else wrote),
     * returning a constant would send EVERY exchange to slot 0 and the buffer would degenerate to a single row
     * that is silently overwritten forever — precisely the "worse than no buffer" failure this whole class
     * exists to avoid. A millisecond ordinal is monotonic, distributes across slots, and sorts correctly; its
     * only cost is that two exchanges completing in the same millisecond may contend for a slot, which is a far
     * smaller lie than a permanently one-row buffer.
     */
    private function nextSequence(): int
    {
        $key = $this->prefix.self::SEQ;

        $next = $this->cache->increment($key);
        if ($next === false) {
            $this->cache->add($key, 0, $this->ttlSeconds);
            $next = $this->cache->increment($key);
        }

        if (is_int($next)) {
            return $next;
        }

        return (int) round(microtime(true) * 1000);
    }

    /** @param array{seq: int, exchange: array<string, mixed>} $value */
    private function put(string $key, array $value): void
    {
        if ($this->ttlSeconds === null) {
            $this->cache->forever($key, $value);

            return;
        }

        $this->cache->put($key, $value, $this->ttlSeconds);
    }
}
