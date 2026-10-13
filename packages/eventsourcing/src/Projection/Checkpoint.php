<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Projection;

use DateTimeImmutable;
use Firefly\EventSourcing\Exception\EventSourcingException;

/**
 * Where one projection stands on the global stream: every event at or below `position` is projected. `lastError` is
 * the failure of the newest batch that rolled back, cleared by the next batch that commits.
 */
final readonly class Checkpoint
{
    public function __construct(
        public string $projection,
        public int $position = 0,
        public ?DateTimeImmutable $updatedAt = null,
        public ?string $lastError = null,
        public ?DateTimeImmutable $failedAt = null,
    ) {}

    public function failed(): bool
    {
        return $this->lastError !== null;
    }

    /** A checkpoint only ever moves forward; the runner advances it to the last position of a non-empty batch. */
    public static function forward(string $projection, int $from, int $to): void
    {
        if ($to <= $from) {
            throw new EventSourcingException("The checkpoint of [{$projection}] only moves forward, but was asked to go from {$from} to {$to}.", 'EVENT_SOURCING_CHECKPOINT', 422);
        }
    }
}
