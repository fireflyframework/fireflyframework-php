<?php

declare(strict_types=1);

namespace Firefly\Eda\DeadLetter;

/**
 * A DeadLetterStore whose entries can be selected and removed — what `firefly:eda:dead-letters:replay` needs. A
 * separate port rather than two new methods on DeadLetterStore, so an application's own store keeps compiling
 * (PROGRAM.md rule 4); both shipped stores implement it.
 */
interface ReplayableDeadLetterStore extends DeadLetterStore
{
    /**
     * @return list<DeadLetterEntry> oldest first
     */
    public function find(DeadLetterQuery $query): array;

    public function remove(string $id): bool;
}
