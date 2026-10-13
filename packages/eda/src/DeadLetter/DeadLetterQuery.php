<?php

declare(strict_types=1);

namespace Firefly\Eda\DeadLetter;

/** Which dead letters to list or replay: by id, by the destination they were dead-lettered from, by event type. */
final readonly class DeadLetterQuery
{
    /**
     * @param  list<string>  $ids  empty = any
     * @param  int|null  $limit  null = all
     */
    public function __construct(
        public array $ids = [],
        public ?string $destination = null,
        public ?string $eventType = null,
        public ?int $limit = 100,
    ) {}

    public function matches(DeadLetterEntry $entry): bool
    {
        return ($this->ids === [] || in_array($entry->id, $this->ids, true))
            && ($this->destination === null || $entry->envelope->destination === $this->destination)
            && ($this->eventType === null || $entry->envelope->eventType === $this->eventType);
    }
}
