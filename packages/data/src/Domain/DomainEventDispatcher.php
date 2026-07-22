<?php

declare(strict_types=1);

namespace Firefly\Data\Domain;

use Firefly\Context\Event\ApplicationEventPublisher;
use Firefly\Domain\RecordsDomainEvents;
use Illuminate\Support\Facades\DB;

/**
 * Publishes a recorder's domain events AFTER a successful transaction commit. dispatchAfterCommit($connection)
 * drains the tracker and queues each event via DB::connection($connection)->afterCommit — the SAME connection the
 * interceptor/template ran on, so a #[Transactional(connection: 'x')] method fires its callbacks on x's commit,
 * not the default connection's. Laravel fires those callbacks only after the OUTERMOST real commit and DISCARDS
 * them on rollback, so a listener never sees an event from a rolled-back unit of work. The template calls
 * dispatchAfterCommit() on both its commit and rollback arms (draining empties the tracker either way — no leak).
 * publishAfterCommit() is the explicit escape hatch for a recorder not saved through a Firefly repository
 * (design §4/§7). Reflection-free.
 */
final class DomainEventDispatcher
{
    public function __construct(
        private readonly AggregateTracker $tracker,
        private readonly ApplicationEventPublisher $publisher,
    ) {}

    public function dispatchAfterCommit(?string $connection = null): void
    {
        foreach ($this->tracker->drainEvents() as $event) {
            $this->afterCommit($event, $connection);
        }
    }

    public function publishAfterCommit(RecordsDomainEvents $aggregate, ?string $connection = null): void
    {
        foreach ($aggregate->pullEvents() as $event) {
            $this->afterCommit($event, $connection);
        }
    }

    private function afterCommit(object $event, ?string $connection): void
    {
        DB::connection($connection)->afterCommit(function () use ($event): void {
            $this->publisher->publish($event);
        });
    }
}
