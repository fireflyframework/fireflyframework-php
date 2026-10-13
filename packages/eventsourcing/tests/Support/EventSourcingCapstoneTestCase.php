<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Tests\Support;

use Firefly\Eda\EdaServiceProvider;
use Firefly\Eda\EdaWiringProvider;
use Firefly\EventSourcing\EventSourcingServiceProvider;
use Firefly\EventSourcing\EventSourcingWiringProvider;
use Firefly\EventSourcing\Manifest\EventSourcingManifest;
use Firefly\EventSourcing\Scanner\EventSourcingScanner;
use Firefly\EventSourcing\Store\EventSourcingSchema;
use Firefly\Resilience\ResilienceServiceProvider;
use Firefly\Scheduling\SchedulingServiceProvider;
use Firefly\Scheduling\SchedulingWiringProvider;
use Firefly\Testing\FireflyDatabaseTestCase;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;

/**
 * The real pipeline: every provider an application discovers for event sourcing (resilience, scheduling, eda,
 * eventsourcing) over Testbench and sqlite :memory:. It runs the database event, snapshot and checkpoint stores, a
 * snapshot every two events, and the in-memory eda bus. The fixtures' EventSourcingManifest is compiled INLINE by the
 * real scanner (what firefly:cache emits), and the capstone beans by the real component compiler.
 */
abstract class EventSourcingCapstoneTestCase extends FireflyDatabaseTestCase
{
    /** @return array<string, string> */
    public static function fixtures(): array
    {
        return [
            'Firefly\\EventSourcing\\Tests\\Fixtures\\Events\\' => dirname(__DIR__).'/Fixtures/Events',
            'Firefly\\EventSourcing\\Tests\\CapstoneFixtures\\' => dirname(__DIR__).'/CapstoneFixtures',
        ];
    }

    /** @return list<class-string> */
    protected function fireflyProviders(): array
    {
        return [
            ResilienceServiceProvider::class,
            SchedulingServiceProvider::class,
            SchedulingWiringProvider::class,
            EdaServiceProvider::class,
            EdaWiringProvider::class,
            EventSourcingServiceProvider::class,
            EventSourcingWiringProvider::class,
            EventSourcingFixturesProvider::class,
        ];
    }

    /** @return array<string, mixed> */
    protected function configOverrides(): array
    {
        return [
            'cache.default' => 'array',
            'queue.default' => 'sync',
            'firefly.eventsourcing.store.provider' => 'database',
            'firefly.eventsourcing.snapshot.threshold' => 2,
        ];
    }

    protected function defineFireflyEnvironment(Application $app): void
    {
        $app->instance(EventSourcingManifest::class, (new EventSourcingScanner)->scan(self::fixtures()));
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Dropped first: on a real server the tables of the last run remain.
        EventSourcingSchema::drop(DB::connection()->getSchemaBuilder());
        EventSourcingSchema::create(DB::connection()->getSchemaBuilder());
    }

    /** @param array<string, mixed> $parameters */
    public function command(string $name, array $parameters = []): int
    {
        return $this->app()->make(Kernel::class)->call($name, $parameters);
    }

    public function commandOutput(): string
    {
        return $this->app()->make(Kernel::class)->output();
    }
}
