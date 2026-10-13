<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Snapshot;

use DateTimeImmutable;

/** An aggregate's state at `version`, in the shape SnapshotAware::snapshotVersion() `stateVersion` describes. */
final readonly class Snapshot
{
    /**
     * @param  array<string, mixed>  $state
     */
    public function __construct(
        public string $aggregateId,
        public string $aggregateType,
        public int $version,
        public array $state,
        public int $stateVersion = 1,
        public DateTimeImmutable $createdAt = new DateTimeImmutable,
    ) {}
}
