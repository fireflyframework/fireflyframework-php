<?php

declare(strict_types=1);

namespace Firefly\Resilience\Store;

use Firefly\Kernel\Exception\Infrastructure\ServiceUnavailableException;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;

/**
 * The default ResilienceStore: Laravel's cache. Every mutator maps to an atomic cache primitive
 * (add/increment/decrement), and withLock uses the store's atomic lock so a token-bucket or breaker
 * transition is a genuine critical section. State persists across FPM requests whenever the driver does
 * (file/database/redis); with the array driver it is per-request (fine for tests/single-shot).
 *
 * withLock separates two numbers the original implementation conflated, which is what made a fail-fast
 * primitive block for seconds and then blow up as a 500:
 *
 *   * $ttlSeconds — how long the mutex is HELD once taken. It bounds the damage of a worker dying inside the
 *     critical section: the lock self-expires instead of leaving a tombstone. Callers pass seconds of
 *     headroom over a section that is really microseconds of work.
 *   * $lockBlockTimeout — how long a caller is willing to WAIT to take the mutex. This is a fail-fast policy
 *     decision that belongs to the store, not to the pattern, and it must be SMALL.
 *
 * The old code passed the same number for both (`$store->lock($key, $seconds)->block($seconds, ...)`) and
 * every caller passed 5.0. A RateLimiter configured with timeout: 0 — i.e. "reject instantly rather than make
 * the caller wait" — would therefore sit for five seconds on a contended key, holding an FPM worker the whole
 * time, and then throw Illuminate\Contracts\Cache\LockTimeoutException: a plain \Exception that no framework
 * error mapper recognises, so it surfaced to the client as a bare HTTP 500. Under exactly the load the rate
 * limiter exists to shed, the limiter became a latency amplifier and an error source of its own.
 *
 * The wait is now its own configurable budget, defaulting to DEFAULT_LOCK_BLOCK_TIMEOUT, and exhausting it
 * raises ServiceUnavailableException — a kernel infrastructure exception that renders as 503 with the
 * retryable error code RESILIENCE_STORE_LOCK_TIMEOUT, which is the honest description of "the shared state
 * store is too contended to answer right now" and is a status a caller, a load balancer and an SLO dashboard
 * all already know how to read.
 */
final class CacheResilienceStore implements ResilienceStore
{
    /**
     * How long a caller waits for the state mutex before giving up, in seconds.
     *
     * Half a second is chosen for a fail-fast primitive: the critical section it guards is a single cache
     * read plus a single cache write, so anything beyond a few milliseconds means real contention, and the
     * poll interval below gives roughly a hundred attempts inside the budget — generous for a legitimate
     * hand-off between two workers, and an order of magnitude below the five seconds that made the old
     * behaviour indistinguishable from a hang.
     */
    public const DEFAULT_LOCK_BLOCK_TIMEOUT = 0.5;

    /**
     * How long to sleep between acquisition attempts, in microseconds.
     *
     * Illuminate\Cache\Lock::block() is deliberately not used here: its wait budget is documented as an int
     * number of seconds and it sleeps a fixed 250ms between attempts, so it cannot express a sub-second
     * budget at all — a 0.5s budget would collapse into "one attempt, then throw", and a 1s budget into
     * "three attempts, spending most of a second asleep". Polling at 5ms lets a genuine hand-off be picked up
     * almost immediately while still bounding the wait precisely.
     */
    private const LOCK_POLL_MICROSECONDS = 5000;

    public function __construct(
        private readonly Repository $cache,
        private readonly float $lockBlockTimeout = self::DEFAULT_LOCK_BLOCK_TIMEOUT,
    ) {}

    public function get(string $key): mixed
    {
        return $this->cache->get($key);
    }

    public function put(string $key, mixed $value, ?float $ttlSeconds = null): void
    {
        $this->cache->put($key, $value, $ttlSeconds === null ? null : (int) ceil($ttlSeconds));
    }

    public function add(string $key, mixed $value, ?float $ttlSeconds = null): bool
    {
        return $this->cache->add($key, $value, $ttlSeconds === null ? null : (int) ceil($ttlSeconds));
    }

    public function increment(string $key, int $by = 1): int
    {
        $result = $this->cache->increment($key, $by);

        return is_int($result) ? $result : $by;
    }

    public function decrement(string $key, int $by = 1): int
    {
        $result = $this->cache->decrement($key, $by);

        return is_int($result) ? $result : -$by;
    }

    public function forget(string $key): void
    {
        $this->cache->forget($key);
    }

    public function withLock(string $key, float $ttlSeconds, callable $callback): mixed
    {
        $store = $this->cache->getStore();
        if (! $store instanceof LockProvider) {
            // The driver has no atomic lock (unusual): run without a critical section rather than fail hard.
            return $callback();
        }

        $lock = $store->lock($key.':lock', (int) max(1, ceil($ttlSeconds > 0 ? $ttlSeconds : 5.0)));
        $deadline = microtime(true) + max(0.0, $this->lockBlockTimeout);

        while (true) {
            if ((bool) $lock->get()) {
                return $this->runAndRelease($lock, $callback);
            }

            // Checked AFTER the attempt so a zero budget still means "try once", not "never try".
            if (microtime(true) >= $deadline) {
                throw new ServiceUnavailableException(
                    sprintf(
                        'Timed out after %.3fs waiting for the resilience state lock [%s].',
                        max(0.0, $this->lockBlockTimeout),
                        $key,
                    ),
                    'RESILIENCE_STORE_LOCK_TIMEOUT',
                );
            }

            usleep(self::LOCK_POLL_MICROSECONDS);
        }
    }

    /**
     * Runs the critical section and releases the mutex whatever happens.
     *
     * The finally is load-bearing: without it, a guarded callable that throws (a breaker's admit() rejecting
     * with CircuitBreakerOpenException does exactly that) would leave the lock held until its TTL expired,
     * and every transition on that key in the meantime would fail-fast into a 503.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function runAndRelease(Lock $lock, callable $callback): mixed
    {
        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }
}
