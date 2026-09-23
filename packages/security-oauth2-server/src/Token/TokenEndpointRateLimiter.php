<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Token;

use Firefly\Resilience\RateLimiter;
use Firefly\Resilience\Store\ResilienceStore;

/**
 * A token bucket per client (or per IP when no client id was presented) at the token endpoint, over
 * firefly/resilience's store — the same cache-backed compare-and-set the RateLimiter uses everywhere, so the
 * limit holds across FPM workers. A limiter is built per key on demand; the store is what carries the state.
 *
 * THIS IS THE ONE UNBOUNDED KEY SPACE IN THE FRAMEWORK, which is why the idle TTL matters more here than for
 * the registry's instances. Those are named in configuration — a fixed, small set — while these are keyed by
 * whatever a caller presents: one bucket per registered client id, and one per IP ADDRESS for every request
 * that presented no client id at all. The set therefore grows with traffic, and a bucket written with no
 * expiry is a cache key that outlives not just the code that created it but the caller it was created for.
 * Every bucket now carries `rate_limit.idle_ttl` (thirty days by default, refreshed on every acquisition,
 * `0` for the old unbounded behaviour), which costs nothing: the bucket refills at `refill_rate` per second
 * and `refill_rate` is refused at boot unless it is positive, so after `max_tokens / refill_rate` seconds of
 * silence — a minute at the defaults — a reclaimed bucket and a live one are the same full bucket.
 */
final class TokenEndpointRateLimiter
{
    /**
     * @param  float|null  $idleTtl  seconds of idleness after which the store may reclaim a bucket,
     *                               refreshed by every acquisition; null, or any non-positive duration,
     *                               never expires.
     */
    public function __construct(
        private readonly ResilienceStore $store,
        private readonly int $maxTokens,
        private readonly float $refillRate,
        private readonly ?float $idleTtl = RateLimiter::DEFAULT_IDLE_TTL,
    ) {}

    public function acquire(string $key): bool
    {
        return (new RateLimiter(
            'firefly:oauth2:token:'.hash('sha256', $key),
            $this->store,
            $this->maxTokens,
            $this->refillRate,
            idleTtl: $this->idleTtl,
        ))->tryAcquire();
    }
}
