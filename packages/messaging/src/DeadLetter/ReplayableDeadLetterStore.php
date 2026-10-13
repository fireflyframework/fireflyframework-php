<?php

declare(strict_types=1);

namespace Firefly\Messaging\DeadLetter;

/**
 * A messaging DeadLetterStore whose entries can be selected and removed — what
 * `firefly:messaging:dead-letters:replay` needs. Separate from DeadLetterStore so an application's own store keeps
 * compiling; both shipped stores implement it.
 */
interface ReplayableDeadLetterStore extends DeadLetterStore
{
    /**
     * @return list<DeadLetterEntry> oldest first
     */
    public function find(DeadLetterQuery $query): array;

    public function remove(string $id): bool;
}
