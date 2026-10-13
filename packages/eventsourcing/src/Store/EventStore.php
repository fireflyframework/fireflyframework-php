<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Store;

use Firefly\EventSourcing\Event\NewEvent;
use Firefly\EventSourcing\Event\StoredEvent;

/**
 * The event-store PORT (jafly's EventStore, PyFly's EventStore protocol). Selected by
 * firefly.eventsourcing.store.provider: `memory` (default, one process) or `database` (portable tables, every
 * driver). Every adapter passes the same EventStoreContract.
 *
 * append() is all-or-nothing and optimistic. With a concrete $expectedVersion, a stream that moved since is a
 * ConcurrencyException (409), as is a stream behind it, which would otherwise open a gap. With
 * ExpectedVersion::ANY the events go on the head. Aggregate ids are unique ACROSS aggregate types.
 *
 * Every read hands events through the registered upcasters, so a reader always sees current event versions.
 * readAll() returns events above $afterPosition in global-position order. Positions are given in commit order and
 * never appear below one already read, so one number is a sufficient checkpoint. They are gap-tolerant: never
 * assume they are contiguous.
 */
interface EventStore
{
    /**
     * @param  list<NewEvent>  $events
     * @return list<StoredEvent> the appended events with their versions; their global positions are given when read
     */
    public function append(string $aggregateId, string $aggregateType, array $events, int $expectedVersion = ExpectedVersion::ANY): array;

    public function load(string $aggregateId, int $fromVersion = 1, ?int $toVersion = null): EventStream;

    /** The stream's version: its last event's aggregateVersion, 0 when it has none. */
    public function version(string $aggregateId): int;

    public function exists(string $aggregateId): bool;

    /** @return list<StoredEvent> */
    public function readAll(int $afterPosition = 0, int $limit = 500, ?EventFilter $filter = null): array;

    /** The highest global position given so far (0 for an empty store). */
    public function currentPosition(): int;

    public function isHealthy(): bool;
}
