<?php

declare(strict_types=1);

namespace Lumen\Tests\EventSourcing;

use Firefly\Resilience\ResilienceServiceProvider;
use Firefly\Scheduling\SchedulingServiceProvider;
use Firefly\Scheduling\SchedulingWiringProvider;
use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Lumen\Tests\Outbox\OutboxDatabaseTestCase;

/**
 * The portable-outbox harness (OutboxDatabaseTestCase: firefly.eda.provider=database, `firefly:eda:consume`) with
 * firefly/eventsourcing on the database store and publishing on. The bound EventPublisher is then the outbox writer,
 * so EventSourcingAutoConfiguration binds the OutboxEventStorePublisher: every appended loan event is written to
 * firefly_eda_outbox on `lumen.loans` INSIDE the append's transaction, beside the wallet's own domain events, which
 * the outbox's pre-commit hook writes in the same transaction.
 */
abstract class LumenEventSourcingOutboxTestCase extends OutboxDatabaseTestCase
{
    use LumenEventSourcing;

    public const string DESTINATION = 'lumen.loans';

    /** @return list<class-string<ServiceProvider>> */
    protected function fireflyProviders(): array
    {
        return [
            ...parent::fireflyProviders(),
            ResilienceServiceProvider::class,
            SchedulingServiceProvider::class,
            SchedulingWiringProvider::class,
            ...self::eventSourcingProviders(),
        ];
    }

    /** @return array<string, mixed> */
    protected function configOverrides(): array
    {
        return [
            ...parent::configOverrides(),
            'cache.default' => 'array',
            'queue.default' => 'sync',
            ...self::eventSourcingConfig(),
            'firefly.eventsourcing.publish.enabled' => true,
            'firefly.eventsourcing.publish.destination' => self::DESTINATION,
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
