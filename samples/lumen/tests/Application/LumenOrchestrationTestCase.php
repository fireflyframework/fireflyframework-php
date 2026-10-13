<?php

declare(strict_types=1);

namespace Lumen\Tests\Application;

use Firefly\Orchestration\Manifest\OrchestrationManifest;
use Firefly\Orchestration\OrchestrationServiceProvider;
use Firefly\Orchestration\OrchestrationWiringProvider;
use Firefly\Orchestration\Persistence\OrchestrationSchema;
use Firefly\Orchestration\Scanner\OrchestrationScanner;
use Firefly\Resilience\ResilienceServiceProvider;
use Firefly\Scheduling\SchedulingServiceProvider;
use Firefly\Scheduling\SchedulingWiringProvider;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Lumen\Tests\LumenTestCase;

/**
 * The lumen boot harness with firefly/orchestration: every provider an application discovers for it (resilience,
 * scheduling, orchestration — eda is already in LumenTestCase), database persistence and a database DLQ on the
 * sample's sqlite :memory: connection, and the app's #[Saga] classes compiled INLINE by the real scanner, which is
 * what firefly:cache would write. Mirrors OrchestrationCapstoneTestCase.
 */
abstract class LumenOrchestrationTestCase extends LumenTestCase
{
    /** @return list<class-string<ServiceProvider>> */
    protected function fireflyProviders(): array
    {
        return [
            ...parent::fireflyProviders(),
            ResilienceServiceProvider::class,
            SchedulingServiceProvider::class,
            SchedulingWiringProvider::class,
            OrchestrationServiceProvider::class,
            OrchestrationWiringProvider::class,
        ];
    }

    /** @return array<string, mixed> */
    protected function configOverrides(): array
    {
        return [
            ...parent::configOverrides(),
            'cache.default' => 'array',
            'queue.default' => 'sync',
            'firefly.orchestration.persistence.provider' => 'database',
            'firefly.orchestration.dlq.store' => 'database',
        ];
    }

    protected function defineFireflyEnvironment(Application $app): void
    {
        parent::defineFireflyEnvironment($app);

        $app->instance(OrchestrationManifest::class, (new OrchestrationScanner)->scan($this->appPsr4()));
    }

    protected function setUp(): void
    {
        parent::setUp();

        OrchestrationSchema::create(DB::connection()->getSchemaBuilder());
    }
}
