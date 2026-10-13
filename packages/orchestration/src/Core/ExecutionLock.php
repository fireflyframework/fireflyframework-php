<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Core;

use Closure;
use Firefly\Orchestration\Core\Exception\ExecutionLockedException;
use Firefly\Scheduling\Lock\DistributedLock;

/**
 * Serializes every writer of one execution (spec decision D6) over the framework's DistributedLock port — the
 * backend `firefly.scheduling.lock.provider` selects (none, cache, postgres). A checkpoint is the WHOLE state
 * in one row, so two processes advancing the same execution would otherwise overwrite each other: a signal
 * delivered while the tick resumes the workflow would be lost.
 *
 * Re-entrant within a process (a synchronous child workflow finishing inside its parent's run calls back into
 * the parent under the parent's lock). Waiting is bounded by `firefly.orchestration.lock.wait`, polling every
 * 50 ms, then ExecutionLockedException (HTTP 409, safe to retry). With the default `none` lock provider there
 * is no exclusion across nodes — a multi-node deployment configures `cache` or `postgres`.
 */
final class ExecutionLock
{
    /** @var array<string, int> */
    private array $held = [];

    public function __construct(
        private readonly DistributedLock $lock,
        private readonly float $ttlSeconds = 300.0,
        private readonly float $waitSeconds = 5.0,
    ) {}

    public static function name(string $correlationId): string
    {
        return 'firefly:orchestration:execution:'.$correlationId;
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function synchronized(string $correlationId, Closure $callback): mixed
    {
        $name = self::name($correlationId);

        if (isset($this->held[$name])) {
            $this->held[$name]++;
            try {
                return $callback();
            } finally {
                $this->held[$name]--;
            }
        }

        $deadline = microtime(true) + $this->waitSeconds;
        while (! $this->lock->tryAcquire($name, $this->ttlSeconds)) {
            if (microtime(true) >= $deadline) {
                throw new ExecutionLockedException($correlationId, $this->waitSeconds);
            }
            usleep(50_000);
        }

        $this->held[$name] = 1;
        try {
            return $callback();
        } finally {
            unset($this->held[$name]);
            $this->lock->release($name);
        }
    }
}
