<?php

declare(strict_types=1);

namespace Lumen\Tests\EventSourcing;

use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Lumen\Tests\Application\LumenOrchestrationTestCase;

/**
 * Chapter 9A's orchestration harness (the payout saga, a database DLQ, orchestration's tables) with
 * firefly.orchestration.persistence.provider=event-sourced: every saga execution is an `orchestration.execution`
 * aggregate in the database event store, snapshotted like any other, and orchestration's own tables are its inline
 * read model on the same connection. Resilience and scheduling are already in the parent.
 */
abstract class LumenEventSourcedOrchestrationTestCase extends LumenOrchestrationTestCase
{
    use LumenEventSourcing;

    /** @return list<class-string<ServiceProvider>> */
    protected function fireflyProviders(): array
    {
        return [...parent::fireflyProviders(), ...self::eventSourcingProviders()];
    }

    /** @return array<string, mixed> */
    protected function configOverrides(): array
    {
        return [
            ...parent::configOverrides(),
            ...self::eventSourcingConfig(),
            'firefly.orchestration.persistence.provider' => 'event-sourced',
        ];
    }

    protected function defineFireflyEnvironment(Application $app): void
    {
        parent::defineFireflyEnvironment($app);

        $this->bindEventSourcingManifest($app);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->freshEventSourcingTables();
    }
}
