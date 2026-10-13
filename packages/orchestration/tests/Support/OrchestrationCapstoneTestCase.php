<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Tests\Support;

use Firefly\Eda\EdaServiceProvider;
use Firefly\Eda\EdaWiringProvider;
use Firefly\Orchestration\Manifest\OrchestrationManifest;
use Firefly\Orchestration\OrchestrationServiceProvider;
use Firefly\Orchestration\OrchestrationWiringProvider;
use Firefly\Orchestration\Persistence\OrchestrationSchema;
use Firefly\Orchestration\Scanner\OrchestrationScanner;
use Firefly\Resilience\ResilienceServiceProvider;
use Firefly\Scheduling\SchedulingServiceProvider;
use Firefly\Scheduling\SchedulingWiringProvider;
use Firefly\Testing\FireflyDatabaseTestCase;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;

/**
 * The real pipeline: every provider an application discovers for orchestration (resilience, scheduling, eda,
 * orchestration) over Testbench + sqlite :memory:, database persistence and a database DLQ, lifecycle events
 * on, the in-memory EDA bus, and the fixtures' OrchestrationManifest compiled INLINE by the real scanner
 * (what firefly:cache emits) — bound before the wiring provider's bound()-guarded default.
 */
abstract class OrchestrationCapstoneTestCase extends FireflyDatabaseTestCase
{
    /** @return array<string, string> */
    public static function fixtures(): array
    {
        return ['Firefly\\Orchestration\\Tests\\CapstoneFixtures\\' => dirname(__DIR__).'/CapstoneFixtures'];
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
            OrchestrationServiceProvider::class,
            OrchestrationWiringProvider::class,
            OrchestrationFixturesProvider::class,
        ];
    }

    /** @return array<string, mixed> */
    protected function configOverrides(): array
    {
        return [
            'cache.default' => 'array',
            'queue.default' => 'sync',
            'firefly.orchestration.persistence.provider' => 'database',
            'firefly.orchestration.dlq.store' => 'database',
            'firefly.orchestration.events.enabled' => true,
        ];
    }

    protected function defineFireflyEnvironment(Application $app): void
    {
        $app->instance(OrchestrationManifest::class, (new OrchestrationScanner)->scan(self::fixtures()));
    }

    protected function setUp(): void
    {
        parent::setUp();

        OrchestrationSchema::create(DB::connection()->getSchemaBuilder());
    }
}
