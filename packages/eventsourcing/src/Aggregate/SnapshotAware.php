<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Aggregate;

/**
 * An aggregate the repository may snapshot (firefly.eventsourcing.snapshot.*). snapshotState() must hold everything
 * replaying would rebuild, as JSON-able values. Raise snapshotVersion() whenever that shape changes: a snapshot of
 * another version is ignored and the aggregate is replayed from its first event, so a stale shape can never be
 * restored.
 */
interface SnapshotAware
{
    /** @return array<string, mixed> */
    public function snapshotState(): array;

    /** @param array<string, mixed> $state */
    public function restoreSnapshot(array $state): void;

    public static function snapshotVersion(): int;
}
