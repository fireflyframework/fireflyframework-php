<?php

declare(strict_types=1);

namespace Firefly\Eda\DeadLetter;

use DateTimeImmutable;
use Firefly\Eda\EventEnvelope;
use Illuminate\Support\Str;
use Throwable;

/**
 * The default DeadLetterStore: an in-process append-only list. Zero external services (skeleton default) — and lost
 * when the process ends; firefly.eda.dead-letter.store=database keeps dead letters durably.
 */
final class InMemoryDeadLetterStore implements ReplayableDeadLetterStore
{
    /** @var list<DeadLetterEntry> */
    private array $entries = [];

    public function store(EventEnvelope $envelope, Throwable $cause): void
    {
        $this->entries[] = new DeadLetterEntry($envelope, $cause::class, $cause->getMessage(), (string) Str::uuid(), new DateTimeImmutable);
    }

    /**
     * @return list<DeadLetterEntry>
     */
    public function all(): array
    {
        return $this->entries;
    }

    public function find(DeadLetterQuery $query): array
    {
        $found = array_values(array_filter($this->entries, $query->matches(...)));

        return $query->limit === null ? $found : array_slice($found, 0, max(1, $query->limit));
    }

    public function remove(string $id): bool
    {
        foreach ($this->entries as $index => $entry) {
            if ($entry->id === $id) {
                array_splice($this->entries, $index, 1);

                return true;
            }
        }

        return false;
    }
}
