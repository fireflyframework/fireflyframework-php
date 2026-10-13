<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Orchestration\Core\CompensationPolicy;
use Firefly\Orchestration\OrchestrationSettings;
use Illuminate\Config\Repository;

/** @param array<array-key, mixed> $orchestration the raw firefly.orchestration block, as a dataset row passes it */
function orchestrationSettings(array $orchestration): OrchestrationSettings
{
    return OrchestrationSettings::fromConfig(new Config(new Repository(['firefly' => ['orchestration' => $orchestration]])));
}

it('defaults to the jafly OrchestrationProperties values a PHP application can honour', function (): void {
    $settings = OrchestrationSettings::defaults();

    expect($settings->saga->enabled)->toBeTrue()
        ->and($settings->saga->compensationPolicy)->toBe(CompensationPolicy::StrictSequential)
        ->and($settings->saga->defaultTimeout)->toBe(300.0)
        ->and($settings->saga->compensationErrorHandler)->toBe('default')
        ->and($settings->tcc->defaultTimeout)->toBe(30.0)
        ->and($settings->workflow->tickInterval)->toBe(60.0)
        ->and($settings->persistence->provider)->toBe('memory')
        ->and($settings->persistence->keyPrefix)->toBe('orchestration:')
        ->and($settings->persistence->keyTtl)->toBeNull()
        ->and($settings->persistence->retentionPeriod)->toBe(604800.0)
        ->and($settings->persistence->cleanupInterval)->toBe(3600.0)
        ->and($settings->recovery->action)->toBe('report')
        ->and($settings->recovery->staleThreshold)->toBe(3600.0)
        ->and($settings->dlq->store)->toBe('memory')
        ->and($settings->events->enabled)->toBeFalse()
        ->and($settings->events->gatewayEnabled)->toBeTrue()
        ->and($settings->rest->enabled)->toBeFalse()
        ->and($settings->rest->path)->toBe('/orchestration')
        ->and($settings->backpressure->strategy)->toBe('none')
        ->and($settings->backpressure->batchSize)->toBe(10)
        ->and($settings->lock->wait)->toBe(5.0)
        ->and(OrchestrationSettings::interval(3600.0))->toBe('3600s');
});

it('reads every override', function (): void {
    $settings = orchestrationSettings([
        'saga' => ['enabled' => false, 'compensation-policy' => 'BEST_EFFORT_PARALLEL', 'default-timeout' => '0', 'compensation-error-handler' => 'robust'],
        'persistence' => ['provider' => 'database', 'connection' => 'pgsql', 'key-ttl' => '1h', 'retention-period' => 'P1D'],
        'recovery' => ['action' => 'compensate', 'stale-threshold' => '15m'],
        'rest' => ['enabled' => true, 'path' => 'ops/orchestration'],
        'backpressure' => ['strategy' => 'circuit-breaker', 'max-in-flight' => 50, 'circuit-breaker' => ['failure-threshold' => 2, 'recovery-timeout' => '5s']],
        'workflow' => ['queue' => ['connection' => 'redis', 'name' => 'workflows']],
    ]);

    expect($settings->saga->enabled)->toBeFalse()
        ->and($settings->saga->compensationPolicy)->toBe(CompensationPolicy::BestEffort)
        ->and($settings->saga->defaultTimeout)->toBe(0.0)
        ->and($settings->persistence->connection)->toBe('pgsql')
        ->and($settings->persistence->keyTtl)->toBe(3600)
        ->and($settings->persistence->retentionPeriod)->toBe(86400.0)
        ->and($settings->recovery->action)->toBe('compensate')
        ->and($settings->rest->path)->toBe('/ops/orchestration')
        ->and($settings->backpressure->maxInFlight)->toBe(50)
        ->and($settings->backpressure->failureThreshold)->toBe(2)
        ->and($settings->backpressure->recoveryTimeout)->toBe(5.0)
        ->and($settings->workflow->queueConnection)->toBe('redis')
        ->and($settings->workflow->queueName)->toBe('workflows');
});

it('refuses a value it cannot honour, naming the key', function (array $orchestration, string $key): void {
    expect(fn () => orchestrationSettings($orchestration))->toThrow(ConfigurationException::class, $key);
})->with([
    [['persistence' => ['provider' => 'redis']], 'firefly.orchestration.persistence.provider'],
    [['dlq' => ['store' => 'cache']], 'firefly.orchestration.dlq.store'],
    [['recovery' => ['action' => 'retry']], 'firefly.orchestration.recovery.action'],
    [['backpressure' => ['strategy' => 'adaptive']], 'firefly.orchestration.backpressure.strategy'],
    [['backpressure' => ['batch-size' => 0]], 'firefly.orchestration.backpressure.batch-size'],
    [['saga' => ['default-timeout' => '-5s']], 'firefly.orchestration.saga.default-timeout'],
    [['workflow' => ['tick' => ['interval' => '0']]], 'firefly.orchestration.workflow.tick.interval'],
    [['rest' => ['path' => '/']], 'firefly.orchestration.rest.path'],
    [['saga' => ['compensation-policy' => 'optimistic']], 'firefly.orchestration.saga.compensation-policy'],
]);
