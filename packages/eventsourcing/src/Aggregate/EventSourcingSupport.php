<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Aggregate;

use Firefly\EventSourcing\Event\EventMapper;
use Firefly\EventSourcing\Metadata\MetadataResolver;
use Firefly\EventSourcing\Snapshot\SnapshotPolicy;
use Firefly\EventSourcing\Snapshot\SnapshotStore;
use Firefly\EventSourcing\Store\EventStore;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Everything an EventSourcedRepository needs, as one bean, so an application repository has a one-line constructor:
 * `parent::__construct($support, Order::class)`.
 */
final class EventSourcingSupport
{
    public function __construct(
        public readonly EventStore $store,
        public readonly EventMapper $mapper,
        public readonly SnapshotStore $snapshots,
        public readonly SnapshotPolicy $snapshotPolicy,
        public readonly MetadataResolver $metadata = new MetadataResolver,
        public readonly LoggerInterface $logger = new NullLogger,
    ) {}

    /**
     * @template T of EventSourcedAggregateRoot
     *
     * @param  class-string<T>  $aggregateClass
     * @return EventSourcedRepository<T>
     */
    public function repository(string $aggregateClass): EventSourcedRepository
    {
        return new EventSourcedRepository($this, $aggregateClass);
    }
}
