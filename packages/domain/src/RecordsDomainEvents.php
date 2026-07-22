<?php

declare(strict_types=1);

namespace Firefly\Domain;

/**
 * The drain-side contract the unit-of-work tracker + after-commit dispatcher depend on: a thing that records and
 * hands off domain events. It exposes ONLY the drain side — raiseEvent() is deliberately absent, because only the
 * aggregate raises its OWN events (it stays protected on the implementer). Implemented by AggregateRoot (a
 * persistence-free aggregate) AND by the HasDomainEvents trait (so a persisted Eloquent Model can BE an
 * auto-dispatching aggregate). Pure PHP — no framework, no reflection.
 */
interface RecordsDomainEvents
{
    /**
     * A non-draining snapshot of the pending events (repeated reads never drain).
     *
     * @return list<DomainEvent>
     */
    public function pendingEvents(): array;

    /**
     * Drain the pending events and return them (leaves the buffer empty).
     *
     * @return list<DomainEvent>
     */
    public function pullEvents(): array;

    public function clearEvents(): void;
}
