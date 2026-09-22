<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Token;

use Firefly\Resilience\RateLimiter;
use Firefly\Resilience\Store\ResilienceStore;

/**
 * A token bucket per client (or per IP when no client id was presented) at the token endpoint, over
 * firefly/resilience's store — the same cache-backed compare-and-set the RateLimiter uses everywhere, so the
 * limit holds across FPM workers. A limiter is built per key on demand; the store is what carries the state.
 */
final class TokenEndpointRateLimiter
{
    public function __construct(
        private readonly ResilienceStore $store,
        private readonly int $maxTokens,
        private readonly float $refillRate,
    ) {}

    public function acquire(string $key): bool
    {
        return (new RateLimiter('firefly:oauth2:token:'.hash('sha256', $key), $this->store, $this->maxTokens, $this->refillRate))->tryAcquire();
    }
}
