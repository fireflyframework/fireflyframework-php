<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Snapshot;

/** firefly.eventsourcing.snapshot.store=memory: one process. */
final class MemorySnapshotStore implements SnapshotStore
{
    /** @var array<string, array<int, Snapshot>> aggregate id => version => snapshot */
    private array $snapshots = [];

    public function save(Snapshot $snapshot): void
    {
        $this->snapshots[$snapshot->aggregateId][$snapshot->version] = $snapshot;
        krsort($this->snapshots[$snapshot->aggregateId]);
    }

    public function latest(string $aggregateId, ?int $atOrBeforeVersion = null): ?Snapshot
    {
        foreach ($this->snapshots[$aggregateId] ?? [] as $version => $snapshot) {
            if ($atOrBeforeVersion === null || $version <= $atOrBeforeVersion) {
                return $snapshot;
            }
        }

        return null;
    }

    public function prune(string $aggregateId, int $keep): int
    {
        $all = $this->snapshots[$aggregateId] ?? [];
        $kept = array_slice($all, 0, max(1, $keep), true);
        $this->snapshots[$aggregateId] = $kept;

        return count($all) - count($kept);
    }

    public function delete(string $aggregateId): int
    {
        $count = count($this->snapshots[$aggregateId] ?? []);
        unset($this->snapshots[$aggregateId]);

        return $count;
    }

    public function isHealthy(): bool
    {
        return true;
    }
}
