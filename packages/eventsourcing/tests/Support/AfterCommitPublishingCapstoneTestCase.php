<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Tests\Support;

/** The capstone with publishing on over the in-memory eda bus: events are published after commit. */
abstract class AfterCommitPublishingCapstoneTestCase extends EventSourcingCapstoneTestCase
{
    /** @return array<string, mixed> */
    protected function configOverrides(): array
    {
        return [...parent::configOverrides(), 'firefly.eventsourcing.publish.enabled' => true, 'firefly.eventsourcing.publish.destination' => 'orders'];
    }
}
