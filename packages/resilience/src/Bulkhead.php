<?php

declare(strict_types=1);

namespace Firefly\Resilience;

use Firefly\Resilience\Exception\BulkheadFullException;
use Firefly\Resilience\Store\ResilienceStore;

/**
 * A cache-backed distributed semaphore. acquire() increments the shared permit counter FIRST, then rejects
 * (decrementing back) if it exceeded maxConcurrent — so two racing workers can never both slip past the
 * limit (increment is atomic). call() acquires on entry and releases in finally; maxWait > 0 briefly polls
 * for a freed permit before rejecting.
 */
final class Bulkhead
{
    public function __construct(
        private readonly string $key,
        private readonly ResilienceStore $store,
        private readonly int $maxConcurrent = 10,
        private readonly float $maxWait = 0.0,
    ) {}

    public function acquire(): bool
    {
        $deadline = microtime(true) + $this->maxWait;

        do {
            $count = $this->store->increment($this->key);
            if ($count <= $this->maxConcurrent) {
                return true;
            }
            $this->store->decrement($this->key);
            if ($this->maxWait <= 0.0) {
                return false;
            }
            usleep(2000);
        } while (microtime(true) < $deadline);

        return false;
    }

    public function release(): void
    {
        $this->store->decrement($this->key);
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
            $this->release();
        }
    }
}
