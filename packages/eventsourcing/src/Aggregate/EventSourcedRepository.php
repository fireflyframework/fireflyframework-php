<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Aggregate;

use Firefly\EventSourcing\Event\EventMetadata;
use Firefly\EventSourcing\Event\StoredEvent;
use Firefly\EventSourcing\Exception\AggregateNotFoundException;
use Firefly\EventSourcing\Snapshot\Snapshot;
use Throwable;

/**
 * Loads and saves one aggregate type (jafly's EventSourcedRepository, PyFly's EventSourcedRepository).
 *
 * find() starts from the newest snapshot when the aggregate is SnapshotAware, snapshots are enabled and the
 * snapshot's state version is the class's. It then replays the events after it, mapped and upcast. With a snapshot
 * it still asks the store whether the stream exists, so a tenant-scoped store can hide another tenant's aggregate
 * even from its snapshot.
 *
 * save() appends the buffered events under the version the aggregate was loaded at. A writer that loaded the same
 * version earlier has already moved the stream, so this one gets a 409 ConcurrencyException: reload and retry. On
 * success the buffer is drained, which is also why data's after-commit dispatch never sees these events, and a
 * snapshot is taken when the save crossed the policy's threshold. A snapshot that cannot be written is logged, never
 * thrown: the events are already stored. Likewise a snapshot store that cannot be read is logged and the aggregate is
 * replayed from its first event, since the events alone always rebuild it.
 *
 * Extend it for a typed application repository:
 * `final class OrderRepository extends EventSourcedRepository { public function __construct(EventSourcingSupport $s) { parent::__construct($s, Order::class); } }`.
 *
 * @template T of EventSourcedAggregateRoot
 */
class EventSourcedRepository
{
    /**
     * @param  class-string<T>  $aggregateClass
     */
    public function __construct(
        private readonly EventSourcingSupport $support,
        private readonly string $aggregateClass,
    ) {}

    /** @return class-string<T> */
    public function aggregateClass(): string
    {
        return $this->aggregateClass;
    }

    /** @return T|null */
    public function find(string $id): ?EventSourcedAggregateRoot
    {
        $class = $this->aggregateClass;
        $snapshot = $this->snapshotFor($id);
        if ($snapshot !== null && ! $this->support->store->exists($id)) {
            return null;
        }

        $from = $snapshot === null ? 0 : $snapshot->version;
        $stream = $this->support->store->load($id, $from + 1);
        if ($snapshot === null && $stream->isEmpty()) {
            return null;
        }

        $history = array_map(fn (StoredEvent $event) => $this->support->mapper->toDomainEvent($event), $stream->events);

        return $class::reconstitute($id, $history, $from, $snapshot?->state);
    }

    /** @return T */
    public function get(string $id): EventSourcedAggregateRoot
    {
        return $this->find($id) ?? throw AggregateNotFoundException::of($this->aggregateClass::aggregateType(), $id);
    }

    public function exists(string $id): bool
    {
        return $this->support->store->exists($id);
    }

    /**
     * @param  T  $aggregate
     * @return list<StoredEvent>
     */
    public function save(EventSourcedAggregateRoot $aggregate, ?EventMetadata $metadata = null): array
    {
        $pending = $aggregate->uncommittedEvents();
        if ($pending === []) {
            return [];
        }

        $resolved = $this->support->metadata->resolve($metadata);
        $events = array_map(fn ($event) => $this->support->mapper->toNewEvent($event, $resolved), $pending);
        $before = $aggregate->committedVersion();

        $stored = $this->support->store->append($aggregate->id(), $this->aggregateClass::aggregateType(), $events, $before);
        $aggregate->markEventsCommitted();
        $this->snapshotIfDue($aggregate, $before);

        return $stored;
    }

    private function snapshotFor(string $id): ?Snapshot
    {
        $class = $this->aggregateClass;
        if (! $this->support->snapshotPolicy->enabled || ! is_subclass_of($class, SnapshotAware::class)) {
            return null;
        }

        try {
            $snapshot = $this->support->snapshots->latest($id);
        } catch (Throwable $failure) {
            $this->support->logger->warning(
                sprintf('Could not read a snapshot of aggregate [%s], replaying it from its first event: %s', $id, $failure->getMessage()),
                ['id' => $id, 'exception' => $failure],
            );

            return null;
        }

        return $snapshot !== null && $snapshot->stateVersion === $class::snapshotVersion() && $snapshot->aggregateType === $class::aggregateType() ? $snapshot : null;
    }

    private function snapshotIfDue(EventSourcedAggregateRoot $aggregate, int $before): void
    {
        $policy = $this->support->snapshotPolicy;
        if (! $aggregate instanceof SnapshotAware || ! $policy->due($before, $aggregate->committedVersion())) {
            return;
        }

        try {
            $this->support->snapshots->save(new Snapshot($aggregate->id(), $aggregate::aggregateType(), $aggregate->committedVersion(), $aggregate->snapshotState(), $aggregate::snapshotVersion()));
            $this->support->snapshots->prune($aggregate->id(), $policy->keepCount);
        } catch (Throwable $failure) {
            $this->support->logger->warning(
                sprintf('Could not snapshot aggregate [%s] at version %d: %s', $aggregate->id(), $aggregate->committedVersion(), $failure->getMessage()),
                ['id' => $aggregate->id(), 'version' => $aggregate->committedVersion(), 'exception' => $failure],
            );
        }
    }
}
