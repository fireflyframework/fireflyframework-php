<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Persistence;

use Closure;
use DateTimeImmutable;
use Firefly\Orchestration\Core\ExecutionStatus;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Throwable;

/**
 * Executions in the Laravel cache (jafly CachePersistenceProvider): one entry per execution under
 * `{key-prefix}execution:{id}` holding the state's array form, plus an index entry listing the ids, which is
 * what every query walks. The index is rewritten under the store's own lock when the store is a LockProvider
 * (array, database, redis, memcached), so two processes adding executions at once cannot drop each other's id.
 *
 * `key-ttl` expires executions; an expired one disappears from every query and the index forgets it at the
 * next cleanup(). This provider walks the index on every query — the right trade-off for the hundreds of live
 * executions a cache is chosen for; thousands of terminal ones belong in `database` with a retention period.
 */
final class CacheExecutionPersistenceProvider implements ExecutionPersistenceProvider
{
    public function __construct(
        private readonly Repository $cache,
        private readonly string $prefix = 'orchestration:',
        private readonly ?int $ttlSeconds = null,
    ) {}

    public function save(ExecutionState $state): void
    {
        $key = $this->key($state->correlationId);
        if ($this->ttlSeconds === null) {
            $this->cache->forever($key, $state->toArray());
        } else {
            $this->cache->put($key, $state->toArray(), $this->ttlSeconds);
        }

        $id = $state->correlationId;
        $this->rewriteIndex(static fn (array $ids): array => in_array($id, $ids, true) ? $ids : [...$ids, $id]);
    }

    public function findById(string $correlationId): ?ExecutionState
    {
        $row = $this->cache->get($this->key($correlationId));

        return is_array($row) ? ExecutionState::fromArray($row) : null;
    }

    public function find(ExecutionCriteria $criteria): array
    {
        return MemoryExecutionPersistenceProvider::newestFirst(array_filter($this->all(), static fn (ExecutionState $state): bool => $criteria->matches($state)), $criteria->limit);
    }

    public function findInFlight(int $limit = 100): array
    {
        return MemoryExecutionPersistenceProvider::newestFirst(array_filter($this->all(), static fn (ExecutionState $state): bool => ! $state->isTerminal()), $limit);
    }

    public function findStale(DateTimeImmutable $before, int $limit = 100): array
    {
        return MemoryExecutionPersistenceProvider::newestFirst(array_filter(
            $this->all(),
            static fn (ExecutionState $state): bool => $state->status->isRecoverable() && $state->updatedAt < $before,
        ), $limit);
    }

    public function findDue(DateTimeImmutable $now, int $limit = 100): array
    {
        $memory = new MemoryExecutionPersistenceProvider;
        foreach ($this->all() as $state) {
            $memory->save($state);
        }

        return $memory->findDue($now, $limit);
    }

    public function findBySearchAttributes(array $criteria, int $limit = 100): array
    {
        return MemoryExecutionPersistenceProvider::newestFirst(array_filter(
            $this->all(),
            static fn (ExecutionState $state): bool => MemoryExecutionPersistenceProvider::hasAttributes($state, $criteria),
        ), $limit);
    }

    public function updateStatus(string $correlationId, ExecutionStatus $status, DateTimeImmutable $now): void
    {
        $state = $this->findById($correlationId);
        if ($state !== null) {
            $this->save($state->withStatus($status, $now));
        }
    }

    public function delete(string $correlationId): bool
    {
        $existed = $this->cache->has($this->key($correlationId));
        $this->cache->forget($this->key($correlationId));
        $this->rewriteIndex(static fn (array $ids): array => array_values(array_filter($ids, static fn (string $id): bool => $id !== $correlationId)));

        return $existed;
    }

    public function cleanup(DateTimeImmutable $before): int
    {
        $removed = 0;
        foreach ($this->ids() as $id) {
            $state = $this->findById($id);
            if ($state === null) {
                $this->delete($id);

                continue;
            }
            if ($state->isTerminal() && $state->updatedAt < $before) {
                $this->delete($id);
                $removed++;
            }
        }

        return $removed;
    }

    public function countInFlight(): int
    {
        return count(array_filter($this->all(), static fn (ExecutionState $state): bool => ! $state->isTerminal()));
    }

    public function countByStatus(): array
    {
        $counts = [];
        foreach ($this->all() as $state) {
            $counts[$state->status->value] = ($counts[$state->status->value] ?? 0) + 1;
        }

        return $counts;
    }

    public function isHealthy(): bool
    {
        try {
            $probe = $this->prefix.'health';
            $this->cache->put($probe, 'ok', 10);

            return $this->cache->get($probe) === 'ok';
        } catch (Throwable) {
            return false;
        }
    }

    private function key(string $correlationId): string
    {
        return $this->prefix.'execution:'.$correlationId;
    }

    /** @return list<string> */
    private function ids(): array
    {
        $ids = $this->cache->get($this->prefix.'index', []);

        return is_array($ids) ? array_values(array_filter($ids, 'is_string')) : [];
    }

    /** @return list<ExecutionState> */
    private function all(): array
    {
        $states = [];
        foreach ($this->ids() as $id) {
            $state = $this->findById($id);
            if ($state !== null) {
                $states[] = $state;
            }
        }

        return $states;
    }

    /**
     * @param  Closure(list<string>): list<string>  $change
     */
    private function rewriteIndex(Closure $change): void
    {
        $apply = function () use ($change): void {
            $this->cache->forever($this->prefix.'index', $change($this->ids()));
        };

        $store = $this->cache->getStore();
        if ($store instanceof LockProvider) {
            $store->lock($this->prefix.'index-lock', 10)->block(5, $apply);

            return;
        }

        $apply();
    }
}
