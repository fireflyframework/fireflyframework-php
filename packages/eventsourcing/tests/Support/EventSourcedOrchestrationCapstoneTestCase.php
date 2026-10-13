<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Tests\Support;

use Firefly\EventSourcing\EventSourcingServiceProvider;
use Firefly\EventSourcing\EventSourcingWiringProvider;
use Firefly\EventSourcing\Store\EventSourcingSchema;
use Firefly\Orchestration\Tests\Support\OrchestrationCapstoneTestCase;
use Illuminate\Support\Facades\DB;

/**
 * W2's orchestration capstone (the attribute-discovered checkout saga and onboarding workflow, the real beans, a
 * database DLQ) with firefly.orchestration.persistence.provider=event-sourced: the executions live in the database
 * event store, snapshotted every three events, with W2's tables as the read model on the same connection.
 */
abstract class EventSourcedOrchestrationCapstoneTestCase extends OrchestrationCapstoneTestCase
{
    /** @return list<class-string> */
    protected function fireflyProviders(): array
    {
        return [...parent::fireflyProviders(), EventSourcingServiceProvider::class, EventSourcingWiringProvider::class];
    }

    /** @return array<string, mixed> */
    protected function configOverrides(): array
    {
        return [
            ...parent::configOverrides(),
            'firefly.orchestration.persistence.provider' => 'event-sourced',
            'firefly.eventsourcing.store.provider' => 'database',
            'firefly.eventsourcing.snapshot.threshold' => 3,
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Dropped first: on a real server (the integration subclass) the tables of the last run remain.
        EventSourcingSchema::drop(DB::connection()->getSchemaBuilder());
        EventSourcingSchema::create(DB::connection()->getSchemaBuilder());
    }
}
