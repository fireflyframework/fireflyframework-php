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
 */
final class RateLimiter
{
    public function __construct(
        private readonly string $key,
        private readonly ResilienceStore $store,
        private readonly int $maxTokens = 10,
        private readonly float $refillRate = 10.0,
        private readonly float $timeout = 0.0,
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

    private function consume(): bool
    {
        return (bool) $this->store->withLock($this->key, 5.0, function (): bool {
            $now = microtime(true);
            $record = $this->store->get($this->key);

            $tokens = is_array($record) && is_numeric($record['tokens'] ?? null) ? (float) $record['tokens'] : (float) $this->maxTokens;
            $ts = is_array($record) && is_numeric($record['ts'] ?? null) ? (float) $record['ts'] : $now;

            $tokens = min((float) $this->maxTokens, $tokens + ($now - $ts) * $this->refillRate);

            if ($tokens >= 1.0) {
                $this->store->put($this->key, ['tokens' => $tokens - 1.0, 'ts' => $now]);

                return true;
            }

            $this->store->put($this->key, ['tokens' => $tokens, 'ts' => $now]);

            return false;
        });
    }
}
