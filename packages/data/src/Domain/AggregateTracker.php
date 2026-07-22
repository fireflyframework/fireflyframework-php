<?php

declare(strict_types=1);

namespace Firefly\Data\Domain;

use Firefly\Domain\DomainEvent;
use Firefly\Domain\RecordsDomainEvents;

/**
 * The request-scoped unit-of-work registry of event recorders touched in the active transaction. EloquentRepository::
 * save() registers each saved RecordsDomainEvents here while a transaction is active (an AggregateRoot OR a
 * Model use HasDomainEvents); the DomainEventDispatcher drains it on commit/rollback. In the share-nothing PHP-FPM
 * runtime a container singleton IS request-scoped, so this is a singleton bean. Reflection-free: dedupe is
 * identity-based (in_array …, true), drain uses the domain interface methods.
 */
final class AggregateTracker
{
    /** @var list<RecordsDomainEvents> */
    private array $aggregates = [];

    public function track(RecordsDomainEvents $aggregate): void
    {
        if (! in_array($aggregate, $this->aggregates, true)) {
            $this->aggregates[] = $aggregate;
        }
    }

    /**
     * @return list<RecordsDomainEvents>
     */
    public function tracked(): array
    {
        return $this->aggregates;
    }

    /**
     * Pull every tracked aggregate's pending events (in raise order) AND empty the registry. Emptying on every
     * drain is what guarantees no leak into the next unit of work — on commit OR rollback.
     *
     * @return list<DomainEvent>
     */
    public function drainEvents(): array
    {
        $events = [];

        foreach ($this->aggregates as $aggregate) {
            foreach ($aggregate->pullEvents() as $event) {
                $events[] = $event;
            }
        }

        $this->aggregates = [];

        return $events;
    }

    public function clear(): void
    {
        foreach ($this->aggregates as $aggregate) {
            $aggregate->clearEvents();
        }

        $this->aggregates = [];
    }
}
