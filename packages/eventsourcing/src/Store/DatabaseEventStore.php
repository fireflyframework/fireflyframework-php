<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Store;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Firefly\Data\Exception\PersistenceExceptionTranslator;
use Firefly\EventSourcing\Event\EventEnvelopeCodec;
use Firefly\EventSourcing\Event\NewEvent;
use Firefly\EventSourcing\Event\PayloadNormalizer;
use Firefly\EventSourcing\Event\StoredEvent;
use Firefly\EventSourcing\Exception\ConcurrencyException;
use Firefly\EventSourcing\Exception\EventSourcingException;
use Firefly\EventSourcing\Exception\StreamTypeMismatchException;
use Firefly\EventSourcing\Publishing\EventStorePublisher;
use Firefly\EventSourcing\Publishing\NoOpEventStorePublisher;
use Firefly\EventSourcing\Upcasting\UpcasterChain;
use Firefly\Kernel\Exception\Infrastructure\DuplicateKeyException;
use Illuminate\Database\Connection;
use Illuminate\Support\Collection;
use Throwable;

/**
 * firefly.eventsourcing.store.provider=database: the portable event store on any Laravel connection.
 *
 * APPEND runs in Connection::transaction(), a SAVEPOINT when the caller already holds a transaction, so it commits
 * with the caller's unit of work. It reads the stream head:
 *
 *  - another aggregate type is refused;
 *  - an expected version AHEAD of the head is a ConcurrencyException (it would leave a gap);
 *  - ExpectedVersion::ANY goes on the head.
 *
 * Then the rows go in, with no global position (CommitOrderNumbering gives positions on read, in commit order).
 * An expected version BEHIND the head reaches UNIQUE(aggregate_id, aggregate_version). That index is the
 * authority, which also holds for two racers that both read the same head: the second one's INSERT waits on the
 * first one's key and fails when it commits. The duplicate key is turned into a ConcurrencyException by firefly/
 * data's PersistenceExceptionTranslator, built here with translation always ON, because conflict detection must
 * not depend on firefly.data.exception-translation.enabled.
 *
 * READS: a stream load and the head check are plain reads. They must never skip a committed row, and on SQL Server
 * they wait for an uncommitted append of the same aggregate, which is the right answer. Global reads go through
 * READPAST on SQL Server.
 */
final class DatabaseEventStore implements EventStore
{
    private const int INSERT_CHUNK = 150;

    /** @var list<string> */
    private const array COLUMNS = ['event_id', 'aggregate_id', 'aggregate_type', 'aggregate_version', 'global_position', 'event_type', 'event_version', 'payload', 'occurred_at', 'recorded_at'];

    private readonly CommitOrderNumbering $numbering;

    private readonly PersistenceExceptionTranslator $translator;

    /**
     * @param  (Closure(): DateTimeImmutable)|null  $clock
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly EventEnvelopeCodec $codec = new EventEnvelopeCodec,
        private readonly UpcasterChain $upcasters = new UpcasterChain,
        private readonly EventStorePublisher $publisher = new NoOpEventStorePublisher,
        int $batchSize = 500,
        private readonly ?Closure $clock = null,
    ) {
        $this->numbering = new CommitOrderNumbering($connection, max(1, $batchSize));
        $this->translator = new PersistenceExceptionTranslator(true);
    }

    public function connection(): Connection
    {
        return $this->connection;
    }

    public function append(string $aggregateId, string $aggregateType, array $events, int $expectedVersion = ExpectedVersion::ANY): array
    {
        if ($events === []) {
            return [];
        }

        EventSourcingSchema::fit('aggregateId', $aggregateId, EventSourcingSchema::ID_LENGTH);
        EventSourcingSchema::fit('aggregateType', $aggregateType, EventSourcingSchema::TYPE_LENGTH);
        $prepared = [];
        foreach ($events as $event) {
            EventSourcingSchema::fit('eventType', $event->eventType, EventSourcingSchema::TYPE_LENGTH);
            EventSourcingSchema::fit('eventId', $event->eventId, EventSourcingSchema::EVENT_ID_LENGTH);
            EventSourcingSchema::fit('tenantId', $event->metadata->tenantId, EventSourcingSchema::TENANT_LENGTH);
            EventSourcingSchema::fit('correlationId', $event->metadata->correlationId, EventSourcingSchema::CORRELATION_LENGTH);
            /** @var array<string, mixed> $payload */
            $payload = PayloadNormalizer::normalize($event->payload);
            $normalized = new NewEvent($event->eventType, $payload, $event->eventVersion, $event->metadata, $event->eventId, $event->occurredAt);
            $prepared[] = [$normalized, $this->codec->encode($normalized, $aggregateType)];
        }

        try {
            /** @var list<StoredEvent> $stored */
            $stored = $this->connection->transaction(function () use ($aggregateId, $aggregateType, $prepared, $expectedVersion): array {
                $base = $this->checkHead($aggregateId, $aggregateType, $expectedVersion);
                $recordedAt = $this->now();
                $rows = [];
                $stored = [];
                foreach ($prepared as $offset => [$event, $raw]) {
                    $version = $base + $offset + 1;
                    $rows[] = [
                        'event_id' => $event->eventId,
                        'aggregate_id' => $aggregateId,
                        'aggregate_type' => $aggregateType,
                        'aggregate_version' => $version,
                        'global_position' => null,
                        'event_type' => $event->eventType,
                        'event_version' => $event->eventVersion,
                        'tenant_id' => $event->metadata->tenantId,
                        'correlation_id' => $event->metadata->correlationId,
                        'payload' => $raw,
                        'occurred_at' => EventSourcingSchema::instant($event->occurredAt),
                        'recorded_at' => EventSourcingSchema::instant($recordedAt),
                    ];
                    $stored[] = new StoredEvent($event->eventId, $aggregateId, $aggregateType, $version, null, $event->eventType, $event->eventVersion, $event->payload, $event->metadata, $event->occurredAt, $recordedAt);
                }

                foreach (array_chunk($rows, self::INSERT_CHUNK) as $chunk) {
                    $this->connection->table(EventSourcingSchema::EVENTS)->insert($chunk);
                }

                $this->publisher->inTransaction($this->connection, $stored);

                return $stored;
            });
        } catch (Throwable $failure) {
            $translated = $this->translator->translate($failure, (string) $this->connection->getDriverName());

            throw $translated instanceof DuplicateKeyException
                ? ConcurrencyException::conflict($aggregateId, $expectedVersion, $this->version($aggregateId), $translated)
                : $translated;
        }

        $this->publisher->afterTransaction($this->connection, $stored);

        return $stored;
    }

    public function load(string $aggregateId, int $fromVersion = 1, ?int $toVersion = null): EventStream
    {
        $query = $this->connection->table(EventSourcingSchema::EVENTS)
            ->where('aggregate_id', $aggregateId)
            ->where('aggregate_version', '>=', max(1, $fromVersion));
        if ($toVersion !== null) {
            $query->where('aggregate_version', '<=', $toVersion);
        }

        return new EventStream($aggregateId, $this->hydrate($query->orderBy('aggregate_version')->get(self::COLUMNS)));
    }

    public function version(string $aggregateId): int
    {
        return self::int($this->connection->table(EventSourcingSchema::EVENTS)->where('aggregate_id', $aggregateId)->max('aggregate_version'));
    }

    public function exists(string $aggregateId): bool
    {
        return $this->version($aggregateId) > 0;
    }

    public function readAll(int $afterPosition = 0, int $limit = 500, ?EventFilter $filter = null): array
    {
        $this->numbering->number();

        $query = $this->connection->table(EventSourcingSchema::EVENTS)
            ->whereNotNull('global_position')
            ->where('global_position', '>', max(0, $afterPosition));
        if ($filter !== null) {
            if ($filter->eventTypes !== []) {
                $query->whereIn('event_type', $filter->eventTypes);
            }
            if ($filter->aggregateTypes !== []) {
                $query->whereIn('aggregate_type', $filter->aggregateTypes);
            }
            if ($filter->occurredFrom !== null) {
                $query->where('occurred_at', '>=', EventSourcingSchema::instant($filter->occurredFrom));
            }
            if ($filter->occurredBefore !== null) {
                $query->where('occurred_at', '<', EventSourcingSchema::instant($filter->occurredBefore));
            }
            if ($filter->tenantId !== null) {
                $query->where('tenant_id', $filter->tenantId);
            }
        }

        return $this->hydrate(EventSourcingSchema::readPast($query, $this->connection)->orderBy('global_position')->limit(max(1, $limit))->get(self::COLUMNS));
    }

    public function currentPosition(): int
    {
        $this->numbering->number();

        return self::int(EventSourcingSchema::readPast($this->connection->table(EventSourcingSchema::EVENTS), $this->connection)->max('global_position'));
    }

    public function isHealthy(): bool
    {
        try {
            EventSourcingSchema::readPast($this->connection->table(EventSourcingSchema::EVENTS), $this->connection)->limit(1)->get(['id']);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** The version a new event goes after, or a refusal; see the class docblock. */
    private function checkHead(string $aggregateId, string $aggregateType, int $expected): int
    {
        $head = $this->connection->table(EventSourcingSchema::EVENTS)
            ->where('aggregate_id', $aggregateId)
            ->orderByDesc('aggregate_version')
            ->limit(1)
            ->first(['aggregate_version', 'aggregate_type']);
        $row = $head === null ? null : (array) $head;
        $version = $row === null ? 0 : self::int($row['aggregate_version'] ?? 0);

        if ($row !== null && self::string($row['aggregate_type'] ?? '') !== $aggregateType) {
            throw StreamTypeMismatchException::of($aggregateId, self::string($row['aggregate_type'] ?? ''), $aggregateType);
        }
        if ($expected === ExpectedVersion::ANY) {
            return $version;
        }
        if ($expected < 0) {
            throw new EventSourcingException("An expected version is ExpectedVersion::ANY or at least 0, got {$expected}.", 'EVENT_STORE_EXPECTED_VERSION', 422);
        }
        if ($expected > $version) {
            throw ConcurrencyException::conflict($aggregateId, $expected, $version);
        }

        return $expected;
    }

    /**
     * @param  Collection<int, \stdClass>  $rows
     * @return list<StoredEvent>
     */
    private function hydrate(Collection $rows): array
    {
        $events = [];
        foreach ($rows as $object) {
            $row = (array) $object;
            $decoded = $this->codec->decode(self::string($row['payload'] ?? ''));
            $position = $row['global_position'] ?? null;
            $events[] = $this->upcasters->apply(new StoredEvent(
                self::string($row['event_id'] ?? ''),
                self::string($row['aggregate_id'] ?? ''),
                self::string($row['aggregate_type'] ?? ''),
                self::int($row['aggregate_version'] ?? 0),
                $position === null ? null : self::int($position),
                self::string($row['event_type'] ?? ''),
                self::int($row['event_version'] ?? 1),
                $decoded['payload'],
                $decoded['metadata'],
                EventSourcingSchema::parseInstant(self::string($row['occurred_at'] ?? '')),
                EventSourcingSchema::parseInstant(self::string($row['recorded_at'] ?? '')),
            ));
        }

        return $events;
    }

    private function now(): DateTimeImmutable
    {
        return ($this->clock === null ? new DateTimeImmutable : ($this->clock)())->setTimezone(new DateTimeZone('UTC'));
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function string(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
