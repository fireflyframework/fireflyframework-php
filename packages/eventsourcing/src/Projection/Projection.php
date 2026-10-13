<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Projection;

use Firefly\EventSourcing\Event\StoredEvent;

/**
 * A read model kept from the global stream (jafly's ProjectionService, PyFly's Projection). The ProjectionRunner
 * feeds it every event after its checkpoint, in commit order, one batch per transaction, and moves the checkpoint in
 * that same transaction. A projection that writes on the checkpoint store's connection is therefore exactly-once:
 * its rows and its checkpoint commit or roll back together. Anything else it touches (a cache, an HTTP call, a
 * second connection) is at-least-once, so its handle() must be idempotent.
 *
 * Make one a #[Component] and the runner finds it, or register it on the ProjectionRegistry bean.
 */
interface Projection
{
    /** Stable and unique: it names the checkpoint row and the lease. */
    public function name(): string;

    /**
     * The event types handle() receives; [] receives every event. The checkpoint moves past the others, so the
     * projection's lag stays true.
     *
     * @return list<string>
     */
    public function handles(): array;

    public function handle(StoredEvent $event): void;

    /** Forgets everything the projection built, before a rebuild replays the stream from position 0. */
    public function reset(): void;
}
