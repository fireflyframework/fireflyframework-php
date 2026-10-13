<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Snapshot;

use Firefly\Data\Exception\PersistenceExceptionTranslator;
use Firefly\EventSourcing\Exception\EventSerializationException;
use Firefly\EventSourcing\Store\EventSourcingSchema;
use Firefly\Kernel\Exception\Infrastructure\DuplicateKeyException;
use Illuminate\Database\Connection;
use JsonException;
use Throwable;

/**
 * firefly.eventsourcing.snapshot.store=database: firefly_eventsourcing_snapshots on the store's connection.
 *
 * The state is JSON with JSON_PRESERVE_ZERO_FRACTION, because a snapshot belongs to its aggregate and must give
 * back 10.0 as 10.0. save() INSERTs and, on the duplicate key of a snapshot of the same version, UPDATEs that row
 * by its exact primary key. There is no MERGE, so SQL Server never waits on another writer's range.
 */
final class DatabaseSnapshotStore implements SnapshotStore
{
    private const int FLAGS = JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    private readonly PersistenceExceptionTranslator $translator;

    public function __construct(private readonly Connection $connection)
    {
        $this->translator = new PersistenceExceptionTranslator(true);
    }

    public function save(Snapshot $snapshot): void
    {
        EventSourcingSchema::fit('aggregateId', $snapshot->aggregateId, EventSourcingSchema::ID_LENGTH);
        EventSourcingSchema::fit('aggregateType', $snapshot->aggregateType, EventSourcingSchema::TYPE_LENGTH);
        try {
            $state = json_encode($snapshot->state, self::FLAGS);
        } catch (JsonException $failure) {
            throw EventSerializationException::at('snapshot.'.$snapshot->aggregateId, 'holds a value JSON cannot represent: '.$failure->getMessage());
        }
        $row = [
            'aggregate_type' => $snapshot->aggregateType,
            'state_version' => $snapshot->stateVersion,
            'state' => $state,
            'created_at' => EventSourcingSchema::instant($snapshot->createdAt),
        ];

        try {
            $this->connection->transaction(fn () => $this->connection->table(EventSourcingSchema::SNAPSHOTS)->insert(['aggregate_id' => $snapshot->aggregateId, 'aggregate_version' => $snapshot->version] + $row));
        } catch (Throwable $failure) {
            $translated = $this->translator->translate($failure, (string) $this->connection->getDriverName());
            if (! $translated instanceof DuplicateKeyException) {
                throw $translated;
            }
            $this->connection->table(EventSourcingSchema::SNAPSHOTS)
                ->where('aggregate_id', $snapshot->aggregateId)
                ->where('aggregate_version', $snapshot->version)
                ->update($row);
        }
    }

    public function latest(string $aggregateId, ?int $atOrBeforeVersion = null): ?Snapshot
    {
        $query = $this->connection->table(EventSourcingSchema::SNAPSHOTS)->where('aggregate_id', $aggregateId);
        if ($atOrBeforeVersion !== null) {
            $query->where('aggregate_version', '<=', $atOrBeforeVersion);
        }
        $found = $query->orderByDesc('aggregate_version')->limit(1)->first();
        if ($found === null) {
            return null;
        }

        $row = (array) $found;
        /** @var array<string, mixed> $state */
        $state = json_decode(is_string($row['state'] ?? null) ? $row['state'] : '{}', true, 512, JSON_THROW_ON_ERROR);

        return new Snapshot(
            $aggregateId,
            is_string($row['aggregate_type'] ?? null) ? $row['aggregate_type'] : '',
            is_numeric($row['aggregate_version'] ?? null) ? (int) $row['aggregate_version'] : 0,
            $state,
            is_numeric($row['state_version'] ?? null) ? (int) $row['state_version'] : 1,
            EventSourcingSchema::parseInstant(is_string($row['created_at'] ?? null) ? $row['created_at'] : 'now'),
        );
    }

    public function prune(string $aggregateId, int $keep): int
    {
        $versions = $this->connection->table(EventSourcingSchema::SNAPSHOTS)
            ->where('aggregate_id', $aggregateId)
            ->orderByDesc('aggregate_version')
            ->pluck('aggregate_version')
            ->all();
        $doomed = array_slice($versions, max(1, $keep));
        if ($doomed === []) {
            return 0;
        }

        return $this->connection->table(EventSourcingSchema::SNAPSHOTS)
            ->where('aggregate_id', $aggregateId)
            ->whereIn('aggregate_version', $doomed)
            ->delete();
    }

    public function delete(string $aggregateId): int
    {
        return $this->connection->table(EventSourcingSchema::SNAPSHOTS)->where('aggregate_id', $aggregateId)->delete();
    }

    public function isHealthy(): bool
    {
        try {
            $this->connection->table(EventSourcingSchema::SNAPSHOTS)->limit(1)->get(['aggregate_id']);

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
