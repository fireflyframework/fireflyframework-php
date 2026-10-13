<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Dlq;

/** Dead letters in this process — the default, and what tests assert against. */
final class MemoryDeadLetterStore implements DeadLetterStore
{
    /** @var array<string, DeadLetterEntry> */
    private array $entries = [];

    public function save(DeadLetterEntry $entry): void
    {
        $this->entries[$entry->id] = $entry;
    }

    public function findById(string $id): ?DeadLetterEntry
    {
        return $this->entries[$id] ?? null;
    }

    public function findAll(int $limit = 100): array
    {
        return self::newestFirst($this->entries, $limit);
    }

    public function findByExecutionName(string $executionName, int $limit = 100): array
    {
        return self::newestFirst(array_filter($this->entries, static fn (DeadLetterEntry $entry): bool => $entry->executionName === $executionName), $limit);
    }

    public function findByCorrelationId(string $correlationId): array
    {
        return self::newestFirst(array_filter($this->entries, static fn (DeadLetterEntry $entry): bool => $entry->correlationId === $correlationId), PHP_INT_MAX);
    }

    public function delete(string $id): bool
    {
        if (! isset($this->entries[$id])) {
            return false;
        }

        unset($this->entries[$id]);

        return true;
    }

    public function count(): int
    {
        return count($this->entries);
    }

    /**
     * @param  array<array-key, DeadLetterEntry>  $entries
     * @return list<DeadLetterEntry>
     */
    private static function newestFirst(array $entries, int $limit): array
    {
        $entries = array_values($entries);
        usort($entries, static fn (DeadLetterEntry $a, DeadLetterEntry $b): int => [$b->createdAt, $a->id] <=> [$a->createdAt, $b->id]);

        return array_slice($entries, 0, $limit);
    }
}
