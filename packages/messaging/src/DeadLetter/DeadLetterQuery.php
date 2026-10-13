<?php

declare(strict_types=1);

namespace Firefly\Messaging\DeadLetter;

/**
 * Which messaging dead letters to list or replay: by id, and by topic — the dead-letter topic the entry is keyed to
 * OR the topic it was dead-lettered from (`x-original-topic`), so `--topic=ledger` and `--topic=ledger.DLT` both work.
 */
final readonly class DeadLetterQuery
{
    /**
     * @param  list<string>  $ids  empty = any
     * @param  int|null  $limit  null = all
     */
    public function __construct(
        public array $ids = [],
        public ?string $topic = null,
        public ?int $limit = 100,
    ) {}

    public function matches(DeadLetterEntry $entry): bool
    {
        return ($this->ids === [] || in_array($entry->id, $this->ids, true))
            && ($this->topic === null
                || $entry->message->topic === $this->topic
                || ($entry->message->headers['x-original-topic'] ?? null) === $this->topic);
    }
}
