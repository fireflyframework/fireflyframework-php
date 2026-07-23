<?php

declare(strict_types=1);

namespace Firefly\Messaging\DeadLetter;

use Firefly\Messaging\Message;
use Throwable;

/**
 * The default messaging DeadLetterStore: an in-process append-only list. Zero external services.
 */
final class InMemoryDeadLetterStore implements DeadLetterStore
{
    /** @var list<DeadLetterEntry> */
    private array $entries = [];

    public function store(Message $message, Throwable $cause): void
    {
        $this->entries[] = new DeadLetterEntry($message, $cause::class, $cause->getMessage());
    }

    /**
     * @return list<DeadLetterEntry>
     */
    public function all(): array
    {
        return $this->entries;
    }
}
