<?php

declare(strict_types=1);

use Firefly\Eda\EdaServiceProvider;
use Firefly\Eda\EdaWiringProvider;
use Firefly\Orchestration\Core\Exception\TopologyValidationException;
use Firefly\Orchestration\Manifest\OrchestrationManifest;
use Firefly\Orchestration\OrchestrationServiceProvider;
use Firefly\Orchestration\OrchestrationWiringProvider;
use Firefly\Orchestration\Scanner\OrchestrationScanner;
use Firefly\Resilience\ResilienceServiceProvider;
use Firefly\Scheduling\SchedulingServiceProvider;
use Firefly\Scheduling\SchedulingWiringProvider;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\Factory as CacheFactoryContract;
use Illuminate\Contracts\Cache\Repository as CacheRepositoryContract;

it('refuses to boot an application whose workflow starts a child nobody registered', function (): void {
    fireflyApplication(
        ['firefly' => []],
        [ResilienceServiceProvider::class, SchedulingServiceProvider::class, SchedulingWiringProvider::class, EdaServiceProvider::class, EdaWiringProvider::class, OrchestrationServiceProvider::class, OrchestrationWiringProvider::class],
        bindings: [
            OrchestrationManifest::class => (new OrchestrationScanner)->scan(['Firefly\\Orchestration\\Tests\\InvalidFixtures\\UnknownChild\\' => dirname(__DIR__).'/InvalidFixtures/UnknownChild']),
            CacheRepositoryContract::class => new CacheRepository(new ArrayStore),
            CacheFactoryContract::class => new class implements CacheFactoryContract
            {
                public function store($name = null)
                {
                    return new CacheRepository(new ArrayStore);
                }
            },
        ],
    );
})->throws(TopologyValidationException::class, 'Workflow [orphan-parent] step [spawn] starts child workflow [does-not-exist], which is not registered');
