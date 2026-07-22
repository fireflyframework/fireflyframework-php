<?php

declare(strict_types=1);

namespace Firefly\Domain;

/**
 * The pending domain-event buffer as a TRAIT, for a class that cannot extend AggregateRoot — most importantly an
 * Eloquent Model (single inheritance is already spent on Model). A Model that
 * `use HasDomainEvents implements RecordsDomainEvents` is BOTH persisted (it IS a Model) AND tracked for
 * after-commit dispatch (it IS RecordsDomainEvents) — one object, both behaviours. NOTE: $pendingEvents is a REAL
 * declared property, NOT a database attribute, so Eloquent's __get/__set magic never touches it (a declared
 * property shadows the magic). Mirrors AggregateRoot exactly: raiseEvent() (protected — only the aggregate raises
 * its own events) plus the three RecordsDomainEvents methods. No reflection.
 */
trait HasDomainEvents
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
