<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Container\Container as FireflyContainer;
use Firefly\Container\Scanner\ComponentManifest;
use Firefly\FeatureFlags\Event\FeatureFlagsChanged;
use Firefly\FeatureFlags\FeatureFlagsSettings;
use Firefly\FeatureFlags\Registry\CacheBook;
use Firefly\FeatureFlags\Registry\FlagRegistry;
use Firefly\FeatureFlags\Source\StoreFlagSource;
use Firefly\FeatureFlags\Store\DatabaseFlagStore;
use Firefly\FeatureFlags\Store\FlagStore;
use Firefly\FeatureFlags\Store\FlagStoreWriter;
use Firefly\FeatureFlags\Tests\Support\FlagStores;
use Firefly\FeatureFlags\Tests\Support\RecordingLogger;
use Firefly\Testing\Double\RecordingApplicationEventPublisher;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Connection;

/** @param Closure(): float $clock */
function featureFlagsStoreRegistry(Connection $connection, Closure $clock, ?RecordingApplicationEventPublisher $events = null): FlagRegistry
{
    $settings = FeatureFlagsSettings::fromConfig(new Config(new ConfigRepository(['firefly' => ['feature-flags' => [
        'enabled' => true, 'sources' => ['store' => ['enabled' => true, 'refresh-interval' => '5s']],
    ]]])));
    $container = new Container;
    $container->instance(FlagStore::class, new DatabaseFlagStore($connection));

    return new FlagRegistry(
        [new StoreFlagSource($settings, new FireflyContainer($container, new ComponentManifest([])))],
        new CacheBook(new Repository(new ArrayStore), new RecordingLogger),
        $events ?? new RecordingApplicationEventPublisher,
        new RecordingLogger,
        $clock,
    );
}

/** @return array{state: string, variants: array{on: bool, off: bool}, defaultVariant: string} */
function featureFlagsStoreDefinition(string $variant = 'off'): array
{
    return ['state' => 'ENABLED', 'variants' => ['on' => true, 'off' => false], 'defaultVariant' => $variant];
}

it('polls a second process after the store interval and rejects an invalid external row as a whole', function (): void {
    $connection = FlagStores::sqlite();
    $now = 1790000000.0;
    $clock = static function () use (&$now): float {
        return $now;
    };
    $first = featureFlagsStoreRegistry($connection, $clock);
    $second = featureFlagsStoreRegistry($connection, $clock);
    $first->document();
    $second->document();
    (new FlagStoreWriter(new DatabaseFlagStore($connection), $first, new RecordingApplicationEventPublisher))
        ->put('good', featureFlagsStoreDefinition(), 'ops');
    expect($second->document()->flag('good'))->toBeNull();
    $now += 5.0;
    expect($second->document()->flag('good')?->defaultVariant())->toBe('off');

    $connection->insert('INSERT INTO firefly_feature_flags (flag_key, definition, version, updated_at, updated_by) VALUES (?, ?, ?, ?, ?)', ['bad key', '{"state":"ENABLED","variants":{"on":true}}', 1, '2026-10-01 12:00:00', null]);
    $connection->insert('INSERT INTO firefly_feature_flag_changes (flag_key, action, definition, previous, actor, changed_at) VALUES (?, ?, ?, ?, ?, ?)', ['bad key', 'put', null, null, null, '2026-10-01 12:00:00']);
    $now += 5.0;
    expect($second->document()->keys())->toBe(['good'])
        ->and($second->states()[0]->status())->toBe('STALE')
        ->and($second->states()[0]->error)->toContain('invalid flag key');
});

it('keeps DOWN before the first committed read and hides manual uncommitted rows from forced refresh', function (): void {
    $connection = FlagStores::sqlite();
    $events = new RecordingApplicationEventPublisher;
    $registry = featureFlagsStoreRegistry($connection, static fn (): float => 1790000000.0, $events);
    $connection->beginTransaction();
    (new DatabaseFlagStore($connection))->put('pending', featureFlagsStoreDefinition(), 'ops');
    $registry->refresh(force: true, only: 'store');
    expect($registry->states()[0]->status())->toBe('DOWN')
        ->and($registry->document()->flag('pending'))->toBeNull()
        ->and($events->ofType(FeatureFlagsChanged::class))->toBe([]);
    $connection->rollBack();
    expect($registry->document()->flag('pending'))->toBeNull();
});

it('retains stale health and last-good state through a nested rollback, then reads after root commit', function (): void {
    $connection = FlagStores::sqlite();
    $now = 1790000000.0;
    $clock = static function () use (&$now): float {
        return $now;
    };
    $events = new RecordingApplicationEventPublisher;
    $registry = featureFlagsStoreRegistry($connection, $clock, $events);
    $store = new DatabaseFlagStore($connection);
    $store->put('good', featureFlagsStoreDefinition(), 'ops');
    $registry->document();
    $connection->insert('INSERT INTO firefly_feature_flags (flag_key, definition, version, updated_at, updated_by) VALUES (?, ?, ?, ?, ?)', ['bad key', '{"state":"ENABLED"}', 1, '2026-10-01 12:00:00', null]);
    $connection->insert('INSERT INTO firefly_feature_flag_changes (flag_key, action, definition, previous, actor, changed_at) VALUES (?, ?, ?, ?, ?, ?)', ['bad key', 'put', null, null, null, '2026-10-01 12:00:00']);
    $now += 5.0;
    $registry->refresh(force: true, only: 'store');
    $stale = $registry->states()[0];
    $count = count($events->ofType(FeatureFlagsChanged::class));
    $connection->beginTransaction();
    $connection->beginTransaction();
    $connection->table('firefly_feature_flags')->where('flag_key', 'bad key')->delete();
    $store->put('pending', featureFlagsStoreDefinition('on'), 'ops');
    $now += 10.0;
    $registry->refresh(force: true, only: 'store');
    expect($registry->states()[0]->toArray())->toBe($stale->toArray())
        ->and($registry->document()->flag('pending'))->toBeNull()
        ->and(count($events->ofType(FeatureFlagsChanged::class)))->toBe($count);
    $connection->rollBack();
    $registry->refresh(force: true, only: 'store');
    expect($registry->states()[0]->toArray())->toBe($stale->toArray());
    $connection->table('firefly_feature_flags')->where('flag_key', 'bad key')->delete();
    $connection->commit();
    $registry->refresh(force: true, only: 'store');
    expect($registry->states()[0]->status())->toBe('UP')
        ->and($registry->document()->flag('good')?->defaultVariant())->toBe('off');
});

it('does not defer a store read for a transaction on another connection', function (): void {
    $storeConnection = FlagStores::sqlite();
    $otherConnection = FlagStores::sqlite();
    $registry = featureFlagsStoreRegistry($storeConnection, static fn (): float => 1790000000.0);
    (new DatabaseFlagStore($storeConnection))->put('committed', featureFlagsStoreDefinition(), 'ops');
    $otherConnection->beginTransaction();
    try {
        expect($registry->document()->flag('committed')?->defaultVariant())->toBe('off')
            ->and($registry->states()[0]->status())->toBe('UP');
    } finally {
        $otherConnection->rollBack();
    }
});

it('publishes and refreshes a writer change only after the store root transaction commits', function (): void {
    $connection = FlagStores::sqlite();
    $events = new RecordingApplicationEventPublisher;
    $registry = featureFlagsStoreRegistry($connection, static fn (): float => 1790000000.0, $events);
    $registry->document();
    $initialChanges = count($events->ofType(FeatureFlagsChanged::class));
    $writer = new FlagStoreWriter(new DatabaseFlagStore($connection), $registry, $events);
    $connection->beginTransaction();
    $writer->put('committed', featureFlagsStoreDefinition(), 'ops');
    $registry->refresh(force: true, only: 'store');
    expect($registry->document()->flag('committed'))->toBeNull()
        ->and(count($events->ofType(FeatureFlagsChanged::class)))->toBe($initialChanges);
    $connection->commit();
    expect($registry->document()->flag('committed')?->defaultVariant())->toBe('off')
        ->and(count($events->ofType(FeatureFlagsChanged::class)))->toBe($initialChanges + 1);
});
