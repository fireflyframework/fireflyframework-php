<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Tests\Support;

/** The event-sourced orchestration capstone on the memory event store, with W2's memory provider as the read model. */
abstract class MemoryEventSourcedOrchestrationCapstoneTestCase extends EventSourcedOrchestrationCapstoneTestCase
{
    /** @return array<string, mixed> */
    protected function configOverrides(): array
    {
        return [...parent::configOverrides(), 'firefly.eventsourcing.store.provider' => 'memory'];
    }
}
