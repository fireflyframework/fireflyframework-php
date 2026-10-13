<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\EventSourcing\EventSourcingSettings;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Illuminate\Config\Repository;

/**
 * @param  array<string, mixed>  $eventsourcing
 * @param  array<string, mixed>  $extra
 */
function eventSourcingSettings(array $eventsourcing = [], array $extra = []): EventSourcingSettings
{
    return EventSourcingSettings::fromConfig(new Config(new Repository(array_replace_recursive([
        'database' => ['default' => 'main'],
        'firefly' => ['eventsourcing' => $eventsourcing],
    ], $extra))));
}

it('reads the defaults the reference documents', function (): void {
    $settings = eventSourcingSettings();

    expect($settings->storeProvider)->toBe('memory')
        ->and($settings->connection)->toBeNull()
        ->and($settings->batchSize)->toBe(500)
        ->and($settings->snapshotEnabled)->toBeTrue()
        ->and($settings->snapshotStore)->toBe('memory')
        ->and($settings->snapshotThreshold)->toBe(50)
        ->and($settings->snapshotKeepCount)->toBe(3)
        ->and($settings->checkpointStore)->toBe('memory')
        ->and($settings->projectionBatchSize)->toBe(100)
        ->and($settings->leaseTtl)->toBe(30.0)
        ->and($settings->pollInterval)->toBe(1.0)
        ->and($settings->maxLag)->toBe(0)
        ->and($settings->publishEnabled)->toBeFalse()
        ->and($settings->publishDestination)->toBe('eventsourcing.events')
        ->and($settings->publishExcludedAggregateTypes)->toBe(['orchestration.execution'])
        ->and($settings->tenancyEnabled)->toBeFalse()
        ->and($settings->captureUser)->toBeTrue()
        ->and($settings->healthEnabled)->toBeTrue()
        ->and($settings->usesDatabase())->toBeFalse();
});

it('moves the snapshot and checkpoint stores with the event store unless they are named', function (): void {
    $follows = eventSourcingSettings(['store' => ['provider' => 'database']]);
    $split = eventSourcingSettings(['store' => ['provider' => 'database'], 'snapshot' => ['store' => 'memory'], 'projection' => ['checkpoint-store' => 'memory']]);

    expect([$follows->snapshotStore, $follows->checkpointStore, $follows->usesDatabase()])->toBe(['database', 'database', true])
        ->and([$split->snapshotStore, $split->checkpointStore])->toBe(['memory', 'memory']);
});

it('refuses a configuration it cannot honour, naming the key', function (array $eventsourcing, string $key): void {
    /** @var array<string, mixed> $eventsourcing */
    expect(fn () => eventSourcingSettings($eventsourcing))->toThrow(ConfigurationException::class, $key);
})->with([
    'store provider' => [['store' => ['provider' => 'redis']], 'firefly.eventsourcing.store.provider'],
    'snapshot store' => [['snapshot' => ['store' => 'file']], 'firefly.eventsourcing.snapshot.store'],
    'checkpoint store' => [['projection' => ['checkpoint-store' => 'cache']], 'firefly.eventsourcing.projection.checkpoint-store'],
    'batch size' => [['store' => ['batch-size' => 0]], 'firefly.eventsourcing.store.batch-size'],
    'threshold' => [['snapshot' => ['threshold' => -1]], 'firefly.eventsourcing.snapshot.threshold'],
    'keep count' => [['snapshot' => ['keep-count' => 0]], 'firefly.eventsourcing.snapshot.keep-count'],
    'projection batch' => [['projection' => ['batch-size' => 0]], 'firefly.eventsourcing.projection.batch-size'],
    'lease' => [['projection' => ['lease-ttl' => '500ms']], 'firefly.eventsourcing.projection.lease-ttl'],
    'poll' => [['projection' => ['poll-interval' => 'soon']], 'firefly.eventsourcing.projection.poll-interval'],
    'max lag' => [['projection' => ['max-lag' => -5]], 'firefly.eventsourcing.projection.max-lag'],
    'destination' => [['publish' => ['destination' => '']], 'firefly.eventsourcing.publish.destination'],
    'connection' => [['store' => ['connection' => '']], 'firefly.eventsourcing.store.connection'],
    'excluded types' => [['publish' => ['exclude-aggregate-types' => ['orders', 7]]], 'firefly.eventsourcing.publish.exclude-aggregate-types'],
    'excluded types shape' => [['publish' => ['exclude-aggregate-types' => 'orders']], 'firefly.eventsourcing.publish.exclude-aggregate-types'],
    'snapshot store type' => [['snapshot' => ['store' => true]], 'firefly.eventsourcing.snapshot.store'],
]);

it('refuses same-transaction publishing when the outbox and the event store use different connections', function (): void {
    expect(fn () => eventSourcingSettings(
        ['store' => ['provider' => 'database', 'connection' => 'events'], 'publish' => ['enabled' => true]],
        ['firefly' => ['eda' => ['provider' => 'database', 'database' => ['connection' => 'outbox']]]],
    ))->toThrow(ConfigurationException::class, 'firefly.eventsourcing.store.connection');

    // The same connection by two spellings (null = the default) is accepted, and so is any after-commit provider.
    expect(eventSourcingSettings(
        ['store' => ['provider' => 'database', 'connection' => 'main'], 'publish' => ['enabled' => true]],
        ['firefly' => ['eda' => ['provider' => 'postgres']]],
    )->publishEnabled)->toBeTrue()
        ->and(eventSourcingSettings(
            ['store' => ['provider' => 'database', 'connection' => 'events'], 'publish' => ['enabled' => true]],
            ['firefly' => ['eda' => ['provider' => 'memory']]],
        )->publishEnabled)->toBeTrue();
});
