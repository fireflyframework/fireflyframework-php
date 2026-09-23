<?php

declare(strict_types=1);

namespace Firefly\Resilience;

use Firefly\Kernel\Exception\Infrastructure\RateLimitExceededException;
use Firefly\Resilience\Store\ResilienceStore;

/**
 * A cache-backed token bucket. The bucket {tokens, ts} lives in the store; each acquisition refills
 * monotonically by (now - ts) * refillRate (capped at maxTokens) and consumes one token, all inside
 * store->withLock so the compare-and-set cannot over-admit across FPM workers. timeout > 0 briefly polls for
 * a refill before giving up.
 *
 * THE BUCKET NOW EXPIRES WHEN NOTHING TOUCHES IT. Every acquisition — granted or refused — rewrites the
 * bucket with idleTtl (DEFAULT_IDLE_TTL, thirty days — the CONSTRUCTOR's default, so a bucket built by hand
 * is bounded exactly like one the registry builds; that matters here more than anywhere, because the
 * limiters built by hand are the ones keyed by client id or by IP address, where the key space grows with
 * traffic rather than with configuration), so a limiter that is being used can never lose its state: the
 * expiry is always pushed further away than the next call. A limiter nobody has called for a month, on the
 * other hand, stops holding a cache key forever, and that costs NOTHING here, more clearly than anywhere
 * else in this package: the bucket refills monotonically at refillRate per second and consume() treats a
 * missing record as a FULL bucket, so after maxTokens/refillRate seconds of idleness a live record and a
 * reclaimed one are the same bucket. Thirty days is that moment many times over for any sane refill rate.
 * The one shape this would change is refillRate 0.0 — a bucket that never refills, i.e. a hard quota rather
 * than a rate — where reclaiming the key would hand the quota back; such a limiter is not idle in the sense
 * this TTL means, and `idle-ttl: null` (no expiry, the pre-wave-N behaviour) is the configuration for it.
 */
final class RateLimiter
{
    /**
     * How long a bucket may sit untouched before the store may reclaim it, when nobody says otherwise:
     * thirty days, the same number `firefly.resilience.rate-limiter.<name>.idle-ttl` defaults to. It is the
     * CONSTRUCTOR's default rather than only the registry's, because the limiters that are NOT registry
     * instances are the per-caller ones (a bucket per client id, a bucket per IP) — the one key space in the
     * framework that grows without bound — and being bounded should not depend on every such caller
     * remembering to ask for it.
     */
    public const float DEFAULT_IDLE_TTL = 2592000.0; // 30 days

    /**
     * @param  float|null  $idleTtl  seconds of idleness after which the store may reclaim this bucket,
     *                               refreshed by every acquisition; null — and any non-positive duration,
     *                               see recordTtl() — never expires. Appended last so every existing
     *                               construction keeps compiling unchanged.
     */
    public function __construct(
        private readonly string $key,
        private readonly ResilienceStore $store,
        private readonly int $maxTokens = 10,
        private readonly float $refillRate = 10.0,
        private readonly float $timeout = 0.0,
        private readonly ?float $idleTtl = self::DEFAULT_IDLE_TTL,
    ) {}

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function call(callable $callback): mixed
    {
        if (! $this->tryAcquire()) {
            throw new RateLimitExceededException;
        }

        return $callback();
    }

    public function tryAcquire(): bool
    {
        $deadline = microtime(true) + $this->timeout;

        do {
            if ($this->consume()) {
                return true;
            }
            if ($this->timeout <= 0.0) {
                return false;
            }
            usleep(2000);
        } while (microtime(true) < $deadline);

        return false;
    }

    /**
     * The TTL every write hands the store — with the one normalisation no store can make for us: a
     * NON-POSITIVE idle TTL means "never expire", never "expire immediately".
     *
     * The distinction is not academic. `Illuminate\Cache\Repository::put()` turns a TTL of zero or less into
     * `forget($key)`, so passing a parsed `0` straight through would make every acquisition a cache DELETE:
     * consume() would find no record, read a FULL bucket, and grant every call forever — a limiter that has
     * stopped limiting, which is the failure mode this whole class exists to prevent. And `0` is exactly the
     * value someone writes to mean "do not expire this" — it is Memcached's convention, and it is what
     * `permit-ttl: 0` already does in Bulkhead::savePermits(). A negative number arrives the same way
     * (`is_numeric` accepts `-1`, so Duration::parse never sees it to refuse it) and is read the same way.
     */
    private function recordTtl(): ?float
    {
        return $this->idleTtl !== null && $this->idleTtl > 0.0 ? $this->idleTtl : null;
    }

    private function consume(): bool
    {
        return (bool) $this->store->withLock($this->key, 5.0, function (): bool {
            $now = microtime(true);
            $record = $this->store->get($this->key);

            $tokens = is_array($record) && is_numeric($record['tokens'] ?? null) ? (float) $record['tokens'] : (float) $this->maxTokens;
            $ts = is_array($record) && is_numeric($record['ts'] ?? null) ? (float) $record['ts'] : $now;

            $tokens = min((float) $this->maxTokens, $tokens + ($now - $ts) * $this->refillRate);

            if ($tokens >= 1.0) {
                $this->store->put($this->key, ['tokens' => $tokens - 1.0, 'ts' => $now], $this->recordTtl());

                return true;
            }

            // A REFUSED acquisition refreshes the TTL too: a limiter that is refusing is the busiest a
            // limiter ever is, and letting its bucket expire under load would hand back a full bucket to
            // exactly the traffic the limit exists to shed.
            $this->store->put($this->key, ['tokens' => $tokens, 'ts' => $now], $this->recordTtl());

            return false;
        });
    }
}
