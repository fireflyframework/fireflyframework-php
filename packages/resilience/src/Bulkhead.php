<?php

declare(strict_types=1);

namespace Firefly\Resilience;

use Firefly\Resilience\Exception\BulkheadFullException;
use Firefly\Resilience\Store\ResilienceStore;
use Throwable;

/**
 * A cache-backed distributed semaphore. call() takes a permit on entry and returns it in finally; maxWait > 0
 * briefly polls for a freed permit before rejecting with BulkheadFullException.
 *
 * PERMITS ARE EXPIRING LEASES, NOT A COUNTER. The original implementation modelled the semaphore as one
 * atomic integer: acquire() incremented it and rejected (decrementing back) when the result exceeded
 * maxConcurrent, release() decremented. That is race-free but it is not crash-safe, and it had two defects
 * of the same family:
 *
 *   1. A worker killed between acquire() and release() (OOM kill, deploy SIGKILL, fatal error) left the
 *      counter incremented FOREVER — no `finally` runs in a process that no longer exists, and nothing in
 *      the design could ever tell a held permit from an abandoned one. Every crash permanently shrank the
 *      bulkhead by one, and after maxConcurrent crashes it rejected 100% of traffic until an operator
 *      flushed the cache by hand. The bulkhead meant to protect the dependency became the outage.
 *   2. A release() with no matching acquire() drove the shared counter NEGATIVE, manufacturing capacity out
 *      of nothing: two stray releases on a max-concurrent-1 bulkhead left it at -2 and the next two callers
 *      both slipped past the limit — precisely the over-admission the primitive exists to prevent.
 *
 * The permit set is therefore a list of expiry timestamps under one store record, pruned on every read, and
 * release() returns a permit THIS instance actually holds (a per-object LIFO of the leases it took, so
 * nested acquisitions unwind correctly and an unmatched release is a no-op).
 *
 * Three trade-offs were taken deliberately:
 *
 *   * A lease that outlives permitTtl is reclaimed while its holder may still be running, so a call slower
 *     than the TTL can be joined by one extra caller — bounded, transient over-admission instead of
 *     unbounded, permanent capacity loss. Set permit-ttl above the longest legitimate guarded call (pairing
 *     the bulkhead with a TimeLimiter makes that bound explicit rather than hopeful).
 *   * acquire()/release() now cost a store mutex round trip instead of a single atomic INCR. That is the
 *     price of being able to distinguish a live permit from a dead one at all; the operation is still one
 *     round trip against the same cache, and correctness under crash beats a marginally cheaper counter.
 *   * On an exotic cache store with no LockProvider, ResilienceStore::withLock degrades to "no critical
 *     section" (see CacheResilienceStore), so the read-modify-write can over-admit under a genuine race
 *     where the old INCR could not. Every first-party Laravel store implements LockProvider, and
 *     CircuitBreaker and RateLimiter already stake their correctness on exactly this seam.
 */
final class Bulkhead
{
    /**
     * How long the store mutex guarding the permit set is HELD — not how long a caller waits to take it (that
     * budget is the store's, see CacheResilienceStore::$lockBlockTimeout). The section is one read and one
     * write; the TTL exists only so a worker killed inside it leaves a self-expiring lock rather than a
     * tombstone every later worker deadlocks on.
     */
    private const LOCK_TTL = 5.0;

    /**
     * The leases THIS instance currently holds, newest last.
     *
     * release() pops from here rather than blindly decrementing a shared number, which is what makes an
     * unmatched release a no-op and lets nested acquire()/release() pairs on the same registry-memoized
     * instance unwind in LIFO order.
     *
     * @var list<float>
     */
    private array $held = [];

    public function __construct(
        private readonly string $key,
        private readonly ResilienceStore $store,
        private readonly int $maxConcurrent = 10,
        private readonly float $maxWait = 0.0,
        private readonly float $permitTtl = 60.0,
    ) {}

    public function acquire(): bool
    {
        $deadline = microtime(true) + $this->maxWait;

        do {
            if ($this->claim()) {
                return true;
            }
            if ($this->maxWait <= 0.0) {
                return false;
            }
            usleep(2000);
        } while (microtime(true) < $deadline);

        return false;
    }

    public function release(): void
    {
        $lease = array_pop($this->held);
        if ($lease === null) {
            return;
        }

        $this->store->withLock($this->key, self::LOCK_TTL, function () use ($lease): void {
            $permits = $this->livingPermits();

            $index = array_search($lease, $permits, true);
            if ($index !== false) {
                unset($permits[$index]);
            }

            $this->savePermits(array_values($permits));
        });
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function call(callable $callback): mixed
    {
        if (! $this->acquire()) {
            throw new BulkheadFullException;
        }

        try {
            return $callback();
        } finally {
            try {
                $this->release();
            } catch (Throwable) {
                // Best effort, and deliberately silent. A throw out of a finally REPLACES whatever the block
                // was about to produce, so an unguarded release() against a store too contended to answer
                // would turn a successful guarded call into an infrastructure 503, or swap the caller's own
                // exception for one the application never raised — in both cases the guarded work has
                // already happened and its outcome would simply be lost. Giving up costs one permit until
                // its lease expires, which is precisely the abandoned-permit case permitTtl already exists
                // to heal. release() itself still propagates, so a caller driving acquire()/release() by
                // hand is told when the store is unwell.
            }
        }
    }

    /**
     * One atomic attempt at a permit: prune the dead, count the living, take a lease if there is room.
     *
     * The pruning is persisted even on rejection, so a bulkhead saturated entirely by crashed holders does
     * not need a successful acquisition to clean itself up.
     */
    private function claim(): bool
    {
        return (bool) $this->store->withLock($this->key, self::LOCK_TTL, function (): bool {
            $permits = $this->livingPermits();

            if (count($permits) >= $this->maxConcurrent) {
                $this->savePermits($permits);

                return false;
            }

            $lease = microtime(true) + $this->permitTtl;
            $permits[] = $lease;
            $this->savePermits($permits);
            $this->held[] = $lease;

            return true;
        });
    }

    /**
     * The permits still within their lease.
     *
     * A record written by an older deploy is a bare integer counter, not an array, so it reads as "no permits
     * held" — a deliberate one-time reset that heals a bulkhead already drained by leaked counts instead of
     * carrying the leak across the deploy that fixes it.
     *
     * @return list<float>
     */
    private function livingPermits(): array
    {
        $raw = $this->store->get($this->key);
        $permits = is_array($raw) ? ($raw['permits'] ?? null) : null;
        if (! is_array($permits)) {
            return [];
        }

        $now = microtime(true);

        $living = [];
        foreach ($permits as $lease) {
            if ((is_int($lease) || is_float($lease)) && (float) $lease > $now) {
                $living[] = (float) $lease;
            }
        }

        return $living;
    }

    /**
     * Persists the permit set, giving the record itself the same TTL as a lease.
     *
     * Every write refreshes it, so the record only expires once nothing has touched the bulkhead for a full
     * TTL — at which point every lease inside it has expired anyway. It is a second, driver-level line of
     * defence: even a store the framework never revisits eventually forgets an abandoned permit set.
     *
     * @param  list<float>  $permits
     */
    private function savePermits(array $permits): void
    {
        $this->store->put($this->key, ['permits' => $permits], $this->permitTtl > 0.0 ? $this->permitTtl : null);
    }
}
