<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Snapshot;

/**
 * Where aggregate snapshots live. Selected by firefly.eventsourcing.snapshot.store (`memory` | `database`, null
 * follows store.provider). Every adapter passes SnapshotStoreContract. A snapshot is an optimization: the
 * repository can always replay, so losing one is never a correctness problem.
 */
interface SnapshotStore
{
    /** Saves the snapshot, replacing one of the same aggregate and version. */
    public function save(Snapshot $snapshot): void;

    public function latest(string $aggregateId, ?int $atOrBeforeVersion = null): ?Snapshot;

    /** Keeps the newest $keep snapshots of the aggregate; returns how many it deleted. */
    public function prune(string $aggregateId, int $keep): int;

    public function delete(string $aggregateId): int;

    public function isHealthy(): bool;
}
