<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Dlq;

/**
 * Where dead letters live (jafly DeadLetterStore): `memory` (default) or `database`
 * (`firefly.orchestration.dlq.store`). Lists are newest first.
 */
interface DeadLetterStore
{
    public function save(DeadLetterEntry $entry): void;

    public function findById(string $id): ?DeadLetterEntry;

    /** @return list<DeadLetterEntry> */
    public function findAll(int $limit = 100): array;

    /** @return list<DeadLetterEntry> */
    public function findByExecutionName(string $executionName, int $limit = 100): array;

    /** @return list<DeadLetterEntry> */
    public function findByCorrelationId(string $correlationId): array;

    public function delete(string $id): bool;

    public function count(): int;
}
