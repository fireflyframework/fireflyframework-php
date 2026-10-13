<?php

declare(strict_types=1);

use Firefly\Context\Boot\ApplicationContext;
use Firefly\Eda\EdaServiceProvider;
use Firefly\Eda\EdaWiringProvider;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Orchestration\Manifest\OrchestrationManifest;
use Firefly\Orchestration\OrchestrationServiceProvider;
use Firefly\Orchestration\OrchestrationSettings;
use Firefly\Orchestration\OrchestrationWiringProvider;
use Firefly\Orchestration\Persistence\ExecutionPersistenceProvider;
use Firefly\Orchestration\Persistence\MemoryExecutionPersistenceProvider;
use Firefly\Orchestration\Saga\Engine\SagaEngine;
use Firefly\Orchestration\Saga\Registry\SagaRegistry;
use Firefly\Resilience\ResilienceServiceProvider;
use Firefly\Scheduling\SchedulingServiceProvider;
use Firefly\Scheduling\SchedulingWiringProvider;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\Factory as CacheFactoryContract;
use Illuminate\Contracts\Cache\Repository as CacheRepositoryContract;
use Illuminate\Foundation\Application;

/**
 * The discovered providers boot over the real pipeline next to the ones they depend on — exactly what
 * `composer require firefly/orchestration` brings. The cache bindings are what a real application has
 * (scheduling's lock and resilience's store take a Repository; see the scheduling-postgres coexistence test).
 *
 * @param  array<string, mixed>  $orchestration
 */
function orchestrationBareApp(array $orchestration = []): Application
{
    return fireflyApplication(
        ['firefly' => ['orchestration' => $orchestration]],
        [ResilienceServiceProvider::class, SchedulingServiceProvider::class, SchedulingWiringProvider::class, EdaServiceProvider::class, EdaWiringProvider::class, OrchestrationServiceProvider::class, OrchestrationWiringProvider::class],
        bindings: [
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
}

it('boots green with the settings eager and the engines lazily available', function (): void {
    $app = orchestrationBareApp();

    expect($app->make(ApplicationContext::class))->toBeInstanceOf(ApplicationContext::class)
        ->and($app->make(OrchestrationSettings::class)->persistence->provider)->toBe('memory')
        ->and($app->make(OrchestrationManifest::class)->isEmpty())->toBeTrue()
        ->and($app->make(ExecutionPersistenceProvider::class))->toBeInstanceOf(MemoryExecutionPersistenceProvider::class)
        ->and($app->make(SagaEngine::class))->toBeInstanceOf(SagaEngine::class)
        ->and($app->make(SagaRegistry::class)->names())->toBe([]);
});

it('refuses at boot a configuration it cannot honour', function (): void {
    orchestrationBareApp(['persistence' => ['provider' => 'redis']]);
})->throws(ConfigurationException::class, 'firefly.orchestration.persistence.provider');
