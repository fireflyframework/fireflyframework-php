<?php

declare(strict_types=1);

namespace Firefly\Domain;

/**
 * The consistency boundary of a DDD cluster and the ONLY entity that raises domain events. Mutating methods
 * append events to a private buffer via raiseEvent(); the framework (firefly/data's after-commit wiring) drains
 * them with pullEvents() after a successful transaction commit and publishes each. pendingEvents() returns a
 * snapshot (reading never drains); pullEvents() drains and returns; clearEvents() drops them (e.g. on rollback).
 * Satisfies RecordsDomainEvents with its OWN buffer (the trait is for the active-record Model case; a purist
 * aggregate extends this class for a persistence-free domain object).
 */
abstract class AggregateRoot extends Entity implements RecordsDomainEvents
{
    /** @var list<DomainEvent> */
    private array $pendingEvents = [];

    protected function raiseEvent(DomainEvent $event): void
    {
        $this->pendingEvents[] = $event;
    }

    /** @return list<DomainEvent> */
    public function pendingEvents(): array
    {
        return $this->pendingEvents;
    }

    /** @return list<DomainEvent> */
    public function pullEvents(): array
    {
        $events = $this->pendingEvents;
        $this->pendingEvents = [];

        return $events;
    }

    public function clearEvents(): void
    {
        $this->pendingEvents = [];
    }
}
