<?php

declare(strict_types=1);

namespace Lumen\Tests\EventSourcing;

use Firefly\Resilience\ResilienceServiceProvider;
use Firefly\Scheduling\SchedulingServiceProvider;
use Firefly\Scheduling\SchedulingWiringProvider;
use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Lumen\Tests\LumenTestCase;

/**
 * The lumen boot harness with firefly/eventsourcing: every provider an application discovers for it (resilience,
 * scheduling — whose DistributedLock the projection runner leases — and eventsourcing; eda is already in
 * LumenTestCase), the database event store on the sample's sqlite :memory: connection, and the in-memory eda bus.
 * Mirrors the package's EventSourcingCapstoneTestCase.
 */
abstract class LumenEventSourcingTestCase extends LumenTestCase
{
    use LumenEventSourcing;

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
