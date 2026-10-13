<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Persistence;

use DateTimeImmutable;
use Firefly\Orchestration\Core\ExecutionStatus;

/**
 * The default provider: an array in this process. Correct for tests, demos and executions that finish within
 * the request that started them; a waiting workflow, a recovery sweep or an ASYNC start needs `cache` or
 * `database`, because nothing outlives the process here.
 */
final class MemoryExecutionPersistenceProvider implements ExecutionPersistenceProvider
{
    /** @var array<string, ExecutionState> */
    private array $states = [];

    public function save(ExecutionState $state): void
    {
        $this->states[$state->correlationId] = $state;
    }

    public function findById(string $correlationId): ?ExecutionState
    {
        return $this->states[$correlationId] ?? null;
    }

    public function find(ExecutionCriteria $criteria): array
    {
        return self::newestFirst(array_filter($this->states, static fn (ExecutionState $state): bool => $criteria->matches($state)), $criteria->limit);
    }

    public function findInFlight(int $limit = 100): array
    {
        return self::newestFirst(array_filter($this->states, static fn (ExecutionState $state): bool => ! $state->isTerminal()), $limit);
    }

    public function findStale(DateTimeImmutable $before, int $limit = 100): array
    {
        return self::newestFirst(array_filter(
            $this->states,
            static fn (ExecutionState $state): bool => $state->status->isRecoverable() && $state->updatedAt < $before,
        ), $limit);
    }

    public function findDue(DateTimeImmutable $now, int $limit = 100): array
    {
        $due = array_values(array_filter(
            $this->states,
            static fn (ExecutionState $state): bool => $state->status === ExecutionStatus::Waiting && $state->wakeAt !== null && $state->wakeAt <= $now,
        ));
        usort($due, static fn (ExecutionState $a, ExecutionState $b): int => [$a->wakeAt, $a->correlationId] <=> [$b->wakeAt, $b->correlationId]);

        return array_slice($due, 0, $limit);
    }

    public function findBySearchAttributes(array $criteria, int $limit = 100): array
    {
        return self::newestFirst(array_filter($this->states, static fn (ExecutionState $state): bool => self::hasAttributes($state, $criteria)), $limit);
    }

    public function updateStatus(string $correlationId, ExecutionStatus $status, DateTimeImmutable $now): void
    {
        $state = $this->states[$correlationId] ?? null;
        if ($state !== null) {
            $this->states[$correlationId] = $state->withStatus($status, $now);
        }
    }

    public function delete(string $correlationId): bool
    {
        if (! isset($this->states[$correlationId])) {
            return false;
        }

        unset($this->states[$correlationId]);

        return true;
    }

    public function cleanup(DateTimeImmutable $before): int
    {
        $removed = 0;
        foreach ($this->states as $id => $state) {
            if ($state->isTerminal() && $state->updatedAt < $before) {
                unset($this->states[$id]);
                $removed++;
            }
        }

        return $removed;
    }

    public function countInFlight(): int
    {
        return count(array_filter($this->states, static fn (ExecutionState $state): bool => ! $state->isTerminal()));
    }

    public function countByStatus(): array
    {
        $counts = [];
        foreach ($this->states as $state) {
            $counts[$state->status->value] = ($counts[$state->status->value] ?? 0) + 1;
        }

        return $counts;
    }

    public function isHealthy(): bool
    {
        return true;
    }

    /**
     * @param  array<string, string|int|float|bool>  $criteria
     */
    public static function hasAttributes(ExecutionState $state, array $criteria): bool
    {
        foreach ($criteria as $key => $value) {
            $actual = $state->searchAttributes[$key] ?? null;
            if ($actual === null || ExecutionState::attributeValue($actual) !== ExecutionState::attributeValue($value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<array-key, ExecutionState>  $states
     * @return list<ExecutionState>
     */
    public static function newestFirst(array $states, int $limit): array
    {
        $states = array_values($states);
        usort($states, static fn (ExecutionState $a, ExecutionState $b): int => [$b->updatedAt, $a->correlationId] <=> [$a->updatedAt, $b->correlationId]);

        return array_slice($states, 0, max(0, $limit));
    }
}
