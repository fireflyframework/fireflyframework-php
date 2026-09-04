<?php

declare(strict_types=1);

namespace Firefly\Resilience\Tests\Fixtures;

use Firefly\Kernel\Exception\Infrastructure\ServiceUnavailableException;
use Firefly\Resilience\Store\InMemoryResilienceStore;
use Firefly\Resilience\Store\ResilienceStore;

/**
 * An InMemoryResilienceStore whose withLock() starts failing the way a genuinely contended
 * CacheResilienceStore does: once the caller's wait budget is exhausted it raises
 * ServiceUnavailableException rather than running the critical section.
 *
 * $failFromCall is 1-based over withLock() invocations, so a test can let the acquisition succeed and make
 * only the RELEASE fail — the ordering that decides whether a cleanup path is allowed to destroy the result
 * or the exception the caller was about to receive.
 */
final class ContendedResilienceStore implements ResilienceStore
{
    private int $lockCalls = 0;

    private readonly InMemoryResilienceStore $inner;

    public function __construct(private readonly int $failFromCall)
    {
        $this->inner = new InMemoryResilienceStore;
    }

    public function get(string $key): mixed
    {
        return $this->inner->get($key);
    }

    public function put(string $key, mixed $value, ?float $ttlSeconds = null): void
    {
        $this->inner->put($key, $value, $ttlSeconds);
    }

    public function add(string $key, mixed $value, ?float $ttlSeconds = null): bool
    {
        return $this->inner->add($key, $value, $ttlSeconds);
    }

    public function increment(string $key, int $by = 1): int
    {
        return $this->inner->increment($key, $by);
    }

    public function decrement(string $key, int $by = 1): int
    {
        return $this->inner->decrement($key, $by);
    }

    public function forget(string $key): void
    {
        $this->inner->forget($key);
    }

    public function withLock(string $key, float $ttlSeconds, callable $callback): mixed
    {
        $this->lockCalls++;

        if ($this->lockCalls >= $this->failFromCall) {
            throw new ServiceUnavailableException(
                sprintf('Timed out after 0.500s waiting for the resilience state lock [%s].', $key),
                'RESILIENCE_STORE_LOCK_TIMEOUT',
            );
        }

        return $this->inner->withLock($key, $ttlSeconds, $callback);
    }
}
