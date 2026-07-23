<?php

declare(strict_types=1);

namespace Firefly\Eda\DeadLetter;

use Firefly\Eda\EventEnvelope;
use Throwable;

/**
 * The default DeadLetterStore: an in-process append-only list. Zero external services (skeleton default).
 */
final class InMemoryDeadLetterStore implements DeadLetterStore
{
    /** @var list<DeadLetterEntry> */
    private array $entries = [];

    public function store(EventEnvelope $envelope, Throwable $cause): void
    {
        $this->entries[] = new DeadLetterEntry($envelope, $cause::class, $cause->getMessage());
    }

    /**
     * @return list<DeadLetterEntry>
     */
    public function all(): array
    {
        return $this->entries;
    }
}
