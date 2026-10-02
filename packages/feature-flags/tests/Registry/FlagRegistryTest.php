<?php

declare(strict_types=1);

use Firefly\Context\Event\ApplicationEventPublisher;
use Firefly\FeatureFlags\Definition\FlagDefinitions;
use Firefly\FeatureFlags\Definition\InvalidFlagDefinition;
use Firefly\FeatureFlags\Definition\Json;
use Firefly\FeatureFlags\Event\FeatureFlagsChanged;
use Firefly\FeatureFlags\Registry\CacheBook;
use Firefly\FeatureFlags\Registry\FlagRegistry;
use Firefly\FeatureFlags\Registry\SourceState;
use Firefly\FeatureFlags\Source\FlagSourceUnavailable;
use Firefly\FeatureFlags\Tests\Support\FixedClock;
use Firefly\FeatureFlags\Tests\Support\FlakyCache;
use Firefly\FeatureFlags\Tests\Support\HookedLockStore;
use Firefly\FeatureFlags\Tests\Support\RecordingLogger;
use Firefly\FeatureFlags\Tests\Support\StubFlagSource;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Testing\Double\RecordingApplicationEventPublisher;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;

/**
 * @param  list<StubFlagSource>  $sources
 */
function featureFlagsRegistry(array $sources, Repository $cache, RecordingApplicationEventPublisher $events, FixedClock $clock, ?RecordingLogger $logger = null): FlagRegistry
{
    $logger ??= new RecordingLogger;

    return new FlagRegistry($sources, new CacheBook($cache, $logger), $events, $logger, $clock(...));
}

/**
 * Every FeatureFlagsChanged published so far, as [origin, changedKeys].
 *
 * @return list<array{0: string, 1: list<string>}>
 */
function featureFlagsChanges(RecordingApplicationEventPublisher $events): array
{
    $changes = [];
    foreach ($events->events as $event) {
        if ($event instanceof FeatureFlagsChanged) {
            $changes[] = [$event->origin, $event->changedKeys];
        }
    }

    return $changes;
}

/**
 * A full boolean flagd definition, with $fields replacing or adding members.
 *
 * @param  array<string, mixed>  $fields
 * @return array<string, mixed>
 */
function featureFlagsRegistryFlag(string $default = 'on', array $fields = []): array
{
    return [...['state' => 'ENABLED', 'variants' => ['on' => true, 'off' => false], 'defaultVariant' => $default], ...$fields];
}

it('lets the highest layer supply a key and records what it shadows', function (): void {
    $config = new StubFlagSource('config', 100, 0.0, ['a' => true, 'c' => 'v1'], failsStartup: true);
    $store = new StubFlagSource('store', 400, 5.0, ['a' => false]);
    $registry = featureFlagsRegistry([$store, $config], new Repository(new ArrayStore), new RecordingApplicationEventPublisher, new FixedClock);

    $composition = $registry->composition();

    expect($composition->flag('a')?->origin)->toBe('store')
        ->and($composition->flag('a')?->overrides)->toBe(['config'])
        ->and($composition->flag('a')?->definition->defaultVariant())->toBe('off')
        ->and($composition->flag('c')?->origin)->toBe('config')
        ->and(array_map(static fn ($state): string => $state->name, $registry->states()))->toBe(['config', 'store'])
        ->and(array_map(static fn ($source): string => $source->name(), $registry->sources()))->toBe(['config', 'store'])
        ->and($registry->source('store'))->toBe($store)
        ->and($registry->source('http'))->toBeNull();
});

it('shares one refresh schedule and one change event between two workers', function (): void {
    $cache = new Repository(new ArrayStore);
    $events = new RecordingApplicationEventPublisher;
    $clock = new FixedClock;
    $store = new StubFlagSource('store', 400, 5.0, ['a' => false, 'b' => 'x']);
    $config = new StubFlagSource('config', 100, 0.0, ['a' => true], failsStartup: true);

    featureFlagsRegistry([$store, $config], $cache, $events, $clock)->document();
    featureFlagsRegistry([$store, $config], $cache, $events, $clock)->document();

    expect($store->loads)->toBe(1)
        ->and(featureFlagsChanges($events))->toBe([['startup', ['a', 'b']]]);
});

it('a long-lived process sees a store change once refresh-interval elapses', function (): void {
    $events = new RecordingApplicationEventPublisher;
    $clock = new FixedClock;
    $store = new StubFlagSource('store', 400, 5.0, ['a' => false]);
    $registry = featureFlagsRegistry([$store], new Repository(new ArrayStore), $events, $clock);
    $registry->document();

    $store->flags = ['a' => true];
    $store->revision = 'r2';
    $clock->now += 1.0;
    $memoized = $registry->document()->flag('a')?->defaultVariant();
    $clock->now += 5.0;
    $refreshed = $registry->document()->flag('a')?->defaultVariant();

    expect($memoized)->toBe('off')
        ->and($refreshed)->toBe('on')
        ->and($store->loads)->toBe(2)
        ->and(featureFlagsChanges($events)[1] ?? null)->toBe(['store', ['a']]);
});

it('a long-lived process re-checks a source whose refresh-interval is 0 on every read', function (): void {
    $events = new RecordingApplicationEventPublisher;
    $file = new StubFlagSource('file', 200, 0.0, ['a' => false], failsStartup: true);
    $registry = featureFlagsRegistry([$file], new Repository(new ArrayStore), $events, new FixedClock);
    $registry->document();

    $file->flags = ['a' => true];
    $file->revision = 'r2';

    expect($registry->document()->flag('a')?->defaultVariant())->toBe('on')
        ->and(featureFlagsChanges($events))->toBe([['startup', ['a']], ['file', ['a']]]);
});

it('never re-reads the configuration of a long-lived process that has no polling source', function (): void {
    $clock = new FixedClock;
    $config = new StubFlagSource('config', 100, 0.0, ['a' => true], failsStartup: true);
    $registry = featureFlagsRegistry([$config], new Repository(new ArrayStore), new RecordingApplicationEventPublisher, $clock);

    $registry->document();
    $clock->now += 86400.0;
    $registry->document();
    $registry->composition();

    expect($config->loads)->toBe(1);
});

it('writes the shared state of the configuration only when it changes, not on every request', function (): void {
    $cache = new class(new ArrayStore) extends Repository
    {
        /** @var list<string> */
        public array $writes = [];

        public function forever($key, $value): bool
        {
            $this->writes[] = is_string($key) ? $key : '';

            return parent::forever($key, $value);
        }
    };
    $config = new StubFlagSource('config', 100, 0.0, ['a' => true], failsStartup: true);

    featureFlagsRegistry([$config], $cache, new RecordingApplicationEventPublisher, new FixedClock)->document();
    featureFlagsRegistry([$config], $cache, new RecordingApplicationEventPublisher, new FixedClock)->document();
    featureFlagsRegistry([$config], $cache, new RecordingApplicationEventPublisher, new FixedClock)->document();

    expect(array_count_values($cache->writes)[CacheBook::PREFIX.'source:config'] ?? 0)->toBe(1)
        ->and($config->loads)->toBe(3);
});

it('keeps the last good document of a failing source, marks it STALE and warns once per error', function (): void {
    $logger = new RecordingLogger;
    $clock = new FixedClock;
    $http = new StubFlagSource('http', 300, 30.0, ['remote' => true]);
    $registry = featureFlagsRegistry([$http], new Repository(new ArrayStore), new RecordingApplicationEventPublisher, $clock, $logger);
    $registry->document();

    $http->failure = new FlagSourceUnavailable('connection refused');
    $clock->now += 31.0;
    $registry->refresh();
    $registry->refresh(force: true);

    expect($registry->document()->flag('remote'))->not->toBeNull()
        ->and($registry->states()[0]->status())->toBe('STALE')
        ->and($registry->states()[0]->error)->toBe('connection refused')
        ->and($logger->count('warning', 'connection refused'))->toBe(1);
});

it('rejects an invalid document as a whole once a source has loaded', function (): void {
    $clock = new FixedClock;
    $file = new StubFlagSource('file', 200, 5.0, ['a' => true], failsStartup: true);
    $registry = featureFlagsRegistry([$file], new Repository(new ArrayStore), new RecordingApplicationEventPublisher, $clock);
    $registry->document();

    $file->flags = ['a' => true, 'bad key' => true];
    $file->revision = 'r2';
    $clock->now += 6.0;

    expect($registry->document()->keys())->toBe(['a'])
        ->and($registry->states()[0]->status())->toBe('STALE')
        ->and($registry->states()[0]->error)->toBe('Invalid feature flag [bad key]: invalid flag key.');
});

it('refuses the boot when config or file fails before ever loading, naming key, reason and source', function (): void {
    $config = new StubFlagSource('config', 100, 0.0, ['bad key' => true], failsStartup: true);
    $registry = featureFlagsRegistry([$config], new Repository(new ArrayStore), new RecordingApplicationEventPublisher, new FixedClock);

    expect(fn () => $registry->start())
        ->toThrow(InvalidFlagDefinition::class, 'Invalid feature flag [bad key] from source [config]: invalid flag key.');
});

it('refuses the boot over an invalid config even when a good one is cached', function (): void {
    $cache = new Repository(new ArrayStore);
    $config = new StubFlagSource('config', 100, 0.0, ['a' => true], failsStartup: true);
    featureFlagsRegistry([$config], $cache, new RecordingApplicationEventPublisher, new FixedClock)->start();

    $config->flags = ['bad key' => true];
    $config->revision = 'r2';
    $nextBoot = featureFlagsRegistry([$config], $cache, new RecordingApplicationEventPublisher, new FixedClock);

    expect(fn () => $nextBoot->start())
        ->toThrow(InvalidFlagDefinition::class, 'Invalid feature flag [bad key] from source [config]: invalid flag key.');
});

it('refuses the boot over a flag file that cannot be read before it ever loaded', function (): void {
    $file = new StubFlagSource('file', 200, 5.0, ['a' => true], failsStartup: true);
    $file->failure = new FlagSourceUnavailable('The flag file [flags.json] does not exist or cannot be read.');
    $registry = featureFlagsRegistry([$file], new Repository(new ArrayStore), new RecordingApplicationEventPublisher, new FixedClock);

    expect(fn () => $registry->start())->toThrow(FlagSourceUnavailable::class, 'does not exist');
});

it('never refuses the boot over a remote or store source; such a source reports DOWN', function (): void {
    $store = new StubFlagSource('store', 400, 5.0, ['a' => true]);
    $store->failure = new FlagSourceUnavailable('no such table');
    $registry = featureFlagsRegistry([$store], new Repository(new ArrayStore), new RecordingApplicationEventPublisher, new FixedClock);

    $registry->start();

    expect($registry->document()->keys())->toBe([])
        ->and($registry->states()[0]->status())->toBe('DOWN')
        ->and($registry->states()[0]->error)->toBe('no such table');
});

it('keeps evaluating from in-process sources when the cache store throws', function (): void {
    $broken = new class(new ArrayStore) extends Repository
    {
        /** @param  UnitEnum|array<array-key, mixed>|string  $key */
        public function get($key, $default = null): mixed
        {
            throw new RuntimeException('redis is gone');
        }

        public function forever($key, $value): bool
        {
            throw new RuntimeException('redis is gone');
        }

        /** @param  UnitEnum|array<array-key, mixed>|string  $key */
        public function add($key, $value, $ttl = null): bool
        {
            throw new RuntimeException('redis is gone');
        }
    };
    $logger = new RecordingLogger;
    $events = new RecordingApplicationEventPublisher;
    $config = new StubFlagSource('config', 100, 0.0, ['kill-switch' => true], failsStartup: true);

    $registry = featureFlagsRegistry([$config], $broken, $events, new FixedClock, $logger);

    expect($registry->document()->flag('kill-switch')?->defaultVariant())->toBe('on')
        ->and($logger->count('warning', 'cannot reach the cache store'))->toBe(1)
        ->and($events->events)->toBe([]);
});

it('a long-lived process announces changes again once the cache store is back', function (): void {
    $cache = new FlakyCache;
    $cache->down = true;
    $events = new RecordingApplicationEventPublisher;
    $clock = new FixedClock;
    $store = new StubFlagSource('store', 400, 5.0, ['a' => false]);
    $registry = featureFlagsRegistry([$store], $cache, $events, $clock);
    $registry->document();

    $cache->down = false;
    $store->flags = ['a' => true];
    $store->revision = 'r2';
    $clock->now += 5.0;

    expect($registry->document()->flag('a')?->defaultVariant())->toBe('on')
        ->and(featureFlagsChanges($events))->toBe([['startup', ['a']]]);
});

it('serves the last good document while another worker holds the refresh lock', function (): void {
    $store = new ArrayStore;
    $cache = new Repository($store);
    $clock = new FixedClock;
    $http = new StubFlagSource('http', 300, 30.0, ['h' => true]);
    featureFlagsRegistry([$http], $cache, new RecordingApplicationEventPublisher, $clock)->document();

    $clock->now += 31.0;
    $held = $store->lock(CacheBook::PREFIX.'lock:http', 60);
    $held->get();
    $other = featureFlagsRegistry([$http], $cache, new RecordingApplicationEventPublisher, $clock);

    expect($other->document()->flag('h'))->not->toBeNull()
        ->and($http->loads)->toBe(1);
    $held->release();
});

it('loads a source on a forced refresh even while another worker holds its lock', function (): void {
    $store = new ArrayStore;
    $clock = new FixedClock;
    $events = new RecordingApplicationEventPublisher;
    $flags = new StubFlagSource('store', 400, 5.0, ['a' => false]);
    $registry = featureFlagsRegistry([$flags], new Repository($store), $events, $clock);
    $registry->document();

    $flags->flags = ['a' => true];
    $flags->revision = 'r2';
    $held = $store->lock(CacheBook::PREFIX.'lock:store', 60);
    $held->get();
    $registry->refresh(force: true, only: 'http');
    $loadsAfterOtherSource = $flags->loads;
    $registry->refresh(force: true, only: 'store');

    expect($loadsAfterOtherSource)->toBe(1)
        ->and($flags->loads)->toBe(2)
        ->and($registry->document()->flag('a')?->defaultVariant())->toBe('on')
        ->and(featureFlagsChanges($events))->toBe([['startup', ['a']], ['store', ['a']]]);
    $held->release();
});

it('never lets a worker that composed an older document overwrite or revert a newer shared record', function (): void {
    $store = new ArrayStore;
    $cache = new Repository($store);
    $events = new RecordingApplicationEventPublisher;
    $clock = new FixedClock;
    $file = new StubFlagSource('file', 200, 5.0, ['a' => true], failsStartup: true);
    $remote = new StubFlagSource('store', 400, 5.0, ['s' => true]);
    featureFlagsRegistry([$file, $remote], $cache, $events, $clock)->document();

    // The file changes. At the interval boundary worker C holds the file's lock and loads it, so worker A serves the
    // old file document and goes on to check the store; while A loads the store, C records the new set.
    $file->flags = ['a' => false];
    $file->revision = 'r2';
    $clock->now += 5.0;
    $held = $store->lock(CacheBook::PREFIX.'lock:file', 60);
    $held->get();
    $remote->beforeLoad = function () use ($held, $file, $remote, $cache, $events, $clock): void {
        $held->release();
        featureFlagsRegistry([$file, $remote], $cache, $events, $clock)->document();
    };
    $servedByA = featureFlagsRegistry([$file, $remote], $cache, $events, $clock)->document()->flag('a')?->defaultVariant();
    $nextRequest = featureFlagsRegistry([$file, $remote], $cache, $events, $clock)->document()->flag('a')?->defaultVariant();

    expect($servedByA)->toBe('on')
        ->and($nextRequest)->toBe('off')
        ->and($remote->loads)->toBe(2)
        ->and(featureFlagsChanges($events))->toBe([['startup', ['a', 's']], ['file', ['a']]]);
});

it('loads a source once at its interval boundary when another worker checked it just before this one took the lock', function (): void {
    $store = new HookedLockStore;
    $cache = new Repository($store);
    $clock = new FixedClock;
    $http = new StubFlagSource('http', 300, 30.0, ['h' => true]);
    featureFlagsRegistry([$http], $cache, new RecordingApplicationEventPublisher, $clock)->document();

    $http->flags = ['h' => false];
    $http->revision = 'r2';
    $clock->now += 30.0;
    $store->beforeLock = function () use ($http, $cache, $clock): void {
        featureFlagsRegistry([$http], $cache, new RecordingApplicationEventPublisher, $clock)->document();
    };
    $late = featureFlagsRegistry([$http], $cache, new RecordingApplicationEventPublisher, $clock);

    expect($late->document()->flag('h')?->defaultVariant())->toBe('off')
        ->and($http->loads)->toBe(2);
});

it('writes the new state of a source before it lets go of the source lock', function (): void {
    $store = new HookedLockStore;
    $cache = new Repository($store);
    $clock = new FixedClock;
    $http = new StubFlagSource('http', 300, 30.0, ['h' => true]);
    $seen = [];
    $store->onRelease = function (string $lock) use ($cache, &$seen): void {
        $seen[] = SourceState::fromArray($cache->get(CacheBook::PREFIX.'source:http'))?->revision;
    };

    featureFlagsRegistry([$http], $cache, new RecordingApplicationEventPublisher, $clock)->document();
    $http->revision = 'r2';
    $clock->now += 30.0;
    featureFlagsRegistry([$http], $cache, new RecordingApplicationEventPublisher, $clock)->document();

    expect($seen)->toBe(['r1', 'r2']);
});

it('keeps its own last good document when another worker re-creates an evicted entry without loading the source', function (): void {
    $cache = new Repository(new ArrayStore);
    $clock = new FixedClock;
    $http = new StubFlagSource('http', 300, 30.0, ['h' => true]);
    $longLived = featureFlagsRegistry([$http], $cache, new RecordingApplicationEventPublisher, $clock);
    $longLived->document();

    $cache->forget(CacheBook::PREFIX.'source:http'); // evicted under memory pressure
    $http->failure = new FlagSourceUnavailable('connection refused');
    $clock->now += 30.0;
    $freshKeys = featureFlagsRegistry([$http], $cache, new RecordingApplicationEventPublisher, $clock)->document()->keys();
    $kept = $longLived->document()->flag('h');

    expect($freshKeys)->toBe([])
        ->and($kept)->not->toBeNull()
        ->and($longLived->states()[0]->status())->toBe('STALE')
        ->and(featureFlagsRegistry([$http], $cache, new RecordingApplicationEventPublisher, $clock)->document()->flag('h'))->not->toBeNull();
});

it('reloads a source whose cached document cannot be read', function (string $document): void {
    $cache = new Repository(new ArrayStore);
    $logger = new RecordingLogger;
    $file = new StubFlagSource('file', 200, 5.0, ['a' => true], failsStartup: true);
    $cache->forever(CacheBook::PREFIX.'source:file', [
        'name' => 'file', 'loaded' => true, 'document' => $document, 'revision' => 'r1',
        'checkedAt' => 1790000000.0, 'lastRefresh' => '2026-09-21T14:13:20Z', 'error' => null, 'flags' => 1,
    ]);
    $registry = featureFlagsRegistry([$file], $cache, new RecordingApplicationEventPublisher, new FixedClock, $logger);

    expect($registry->document()->flag('a')?->defaultVariant())->toBe('on')
        ->and($file->loads)->toBe(1)
        ->and(SourceState::fromArray($cache->get(CacheBook::PREFIX.'source:file'))?->document()->keys())->toBe(['a'])
        ->and($logger->count('warning', 'cached document of feature flag source [file] cannot be read'))->toBe(1);
})->with([
    'not JSON' => ['{"flags": {'],
    'another shape' => ['[["a", true]]'],
]);

it('puts test overrides on top without sharing them', function (): void {
    $registry = featureFlagsRegistry([new StubFlagSource('config', 100, 0.0, ['a' => true], failsStartup: true)], new Repository(new ArrayStore), new RecordingApplicationEventPublisher, new FixedClock);
    $overrides = FlagDefinitions::parseDocument(['flags' => Json::object(FlagDefinitions::normalize(['a' => false]))]);
    $registry->overrideForTests($overrides);

    expect($registry->composition()->flag('a')?->origin)->toBe(FlagRegistry::TEST_LAYER)
        ->and($registry->composition()->flag('a')?->overrides)->toBe(['config'])
        ->and($registry->composition(false)->flag('a')?->origin)->toBe('config')
        ->and($registry->document()->flag('a')?->defaultVariant())->toBe('off')
        ->and($registry->testOverrides())->toBe($overrides);

    $registry->overrideForTests(null);

    expect($registry->composition()->flag('a')?->origin)->toBe('config')
        ->and($registry->document()->flag('a')?->defaultVariant())->toBe('on')
        ->and($registry->testOverrides())->toBeNull();
});

it('announces what test overrides change with origin test-overrides, and nothing when they change nothing', function (): void {
    $events = new RecordingApplicationEventPublisher;
    $registry = featureFlagsRegistry([new StubFlagSource('config', 100, 0.0, ['a' => true, 'b' => true], failsStartup: true)], new Repository(new ArrayStore), $events, new FixedClock);
    $registry->document();

    $registry->overrideForTests(FlagDefinitions::parseDocument(['flags' => Json::object(FlagDefinitions::normalize(['a' => false, 'c' => 'v2']))]));
    $registry->overrideForTests(FlagDefinitions::parseDocument(['flags' => Json::object(FlagDefinitions::normalize(['a' => false, 'c' => 'v2']))]));
    $registry->overrideForTests(null);

    expect(featureFlagsChanges($events))->toBe([
        ['startup', ['a', 'b']],
        [FlagRegistry::TEST_OVERRIDES_ORIGIN, ['a', 'c']],
        [FlagRegistry::TEST_OVERRIDES_ORIGIN, ['a', 'c']],
    ]);
});

it('announces nothing for a key an active test override shadows when its source changes it', function (): void {
    $events = new RecordingApplicationEventPublisher;
    $clock = new FixedClock;
    $file = new StubFlagSource('file', 200, 5.0, ['a' => true, 'b' => true], failsStartup: true);
    $registry = featureFlagsRegistry([$file], new Repository(new ArrayStore), $events, $clock);
    $registry->overrideForTests(FlagDefinitions::parseDocument(['flags' => Json::object(FlagDefinitions::normalize(['a' => 'pinned']))]));

    $file->flags = ['a' => false, 'b' => false];
    $file->revision = 'r2';
    $clock->now += 5.0;
    $registry->document();
    $file->flags = ['a' => 'v3', 'b' => false];
    $file->revision = 'r3';
    $clock->now += 5.0;
    $registry->document();

    expect($file->loads)->toBe(3)
        ->and($registry->composition(false)->flag('a')?->definition->defaultVariant())->toBe('v3')
        ->and($registry->document()->flag('a')?->defaultVariant())->toBe('pinned')
        ->and(featureFlagsChanges($events))->toBe([
            ['startup', ['a', 'b']],
            [FlagRegistry::TEST_OVERRIDES_ORIGIN, ['a']],
            ['file', ['b']],
        ]);
});

it('announces a key a test override shadowed, with its source definition, once the override is cleared', function (): void {
    $events = new RecordingApplicationEventPublisher;
    $clock = new FixedClock;
    $file = new StubFlagSource('file', 200, 5.0, ['a' => true], failsStartup: true);
    $registry = featureFlagsRegistry([$file], new Repository(new ArrayStore), $events, $clock);
    $registry->overrideForTests(FlagDefinitions::parseDocument(['flags' => Json::object(FlagDefinitions::normalize(['a' => 'pinned']))]));
    $file->flags = ['a' => false];
    $file->revision = 'r2';
    $clock->now += 5.0;
    $registry->document();

    $registry->overrideForTests(null);

    expect($registry->document()->flag('a')?->defaultVariant())->toBe('off')
        ->and($registry->composition()->flag('a')?->origin)->toBe('file')
        ->and(featureFlagsChanges($events))->toBe([
            ['startup', ['a']],
            [FlagRegistry::TEST_OVERRIDES_ORIGIN, ['a']],
            [FlagRegistry::TEST_OVERRIDES_ORIGIN, ['a']],
        ]);
});

it('announces a test-overridden key whose targeting references an evaluator its source changes', function (): void {
    $events = new RecordingApplicationEventPublisher;
    $clock = new FixedClock;
    $file = new StubFlagSource('file', 200, 5.0, ['x' => true], failsStartup: true);
    $file->evaluators = ['is-beta' => ['in' => [['var' => 'role'], ['beta']]]];
    $registry = featureFlagsRegistry([$file], new Repository(new ArrayStore), $events, $clock);
    $registry->overrideForTests(FlagDefinitions::parseDocument(['flags' => Json::object([
        'a' => featureFlagsRegistryFlag('off', ['targeting' => ['if' => [['$ref' => 'is-beta'], 'on', 'off']]]),
    ])]));

    $file->evaluators = ['is-beta' => ['in' => [['var' => 'role'], ['beta', 'tester']]]];
    $file->revision = 'r2';
    $clock->now += 5.0;
    $registry->document();

    expect(featureFlagsChanges($events))->toBe([
        ['startup', ['x']],
        [FlagRegistry::TEST_OVERRIDES_ORIGIN, ['a']],
        ['file', ['a']],
    ]);
});

it('announces a key when any field of its composed definition changes, value types included', function (array $before, array $after): void {
    $events = new RecordingApplicationEventPublisher;
    $clock = new FixedClock;
    $file = new StubFlagSource('file', 200, 5.0, ['a' => $before, 'b' => featureFlagsRegistryFlag()], failsStartup: true);
    $registry = featureFlagsRegistry([$file], new Repository(new ArrayStore), $events, $clock);
    $registry->document();

    $file->flags = ['a' => $after, 'b' => featureFlagsRegistryFlag()];
    $file->revision = 'r2';
    $clock->now += 5.0;
    $registry->document();

    expect(featureFlagsChanges($events))->toBe([['startup', ['a', 'b']], ['file', ['a']]]);
})->with([
    'state' => [featureFlagsRegistryFlag(), featureFlagsRegistryFlag(fields: ['state' => 'DISABLED'])],
    'default variant' => [featureFlagsRegistryFlag(), featureFlagsRegistryFlag('off')],
    'targeting' => [featureFlagsRegistryFlag(), featureFlagsRegistryFlag(fields: ['targeting' => ['if' => [['==' => [['var' => 'tier'], 'gold']], 'on', 'off']]])],
    'metadata only' => [featureFlagsRegistryFlag(fields: ['metadata' => ['expires' => '2027-01-01']]), featureFlagsRegistryFlag(fields: ['metadata' => ['expires' => '2027-06-30']])],
    'a field flagd does not read' => [featureFlagsRegistryFlag(), featureFlagsRegistryFlag(fields: ['notes' => 'see ADR-12'])],
    'true becomes 1' => [featureFlagsRegistryFlag(), featureFlagsRegistryFlag(fields: ['variants' => ['on' => 1, 'off' => 0]])],
    '1 becomes 1.0' => [
        ['state' => 'ENABLED', 'variants' => ['one' => 1, 'two' => 2], 'defaultVariant' => 'one'],
        ['state' => 'ENABLED', 'variants' => ['one' => 1.0, 'two' => 2], 'defaultVariant' => 'one'],
    ],
]);

it('announces a key that disappears, and one that appears', function (): void {
    $events = new RecordingApplicationEventPublisher;
    $clock = new FixedClock;
    $file = new StubFlagSource('file', 200, 5.0, ['a' => true, 'b' => true], failsStartup: true);
    $registry = featureFlagsRegistry([$file], new Repository(new ArrayStore), $events, $clock);
    $registry->document();

    $file->flags = ['a' => true, 'c' => true];
    $file->revision = 'r2';
    $clock->now += 5.0;
    $registry->document();

    expect(featureFlagsChanges($events)[1] ?? null)->toBe(['file', ['b', 'c']]);
});

it('announces the keys that depend on a changed evaluator, directly or through another', function (): void {
    $events = new RecordingApplicationEventPublisher;
    $clock = new FixedClock;
    $flags = [
        'direct' => featureFlagsRegistryFlag('off', ['targeting' => ['if' => [['$ref' => 'is-beta'], 'on', 'off']]]),
        'nested' => featureFlagsRegistryFlag('off', ['targeting' => ['if' => [['$ref' => 'vip'], 'on', 'off']]]),
        'other' => featureFlagsRegistryFlag('off', ['targeting' => ['if' => [['$ref' => 'is-staff'], 'on', 'off']]]),
        'static' => featureFlagsRegistryFlag(),
        // only targeting is expanded: a variant that looks like a reference is a value
        'literal' => ['state' => 'ENABLED', 'variants' => ['ref' => ['$ref' => 'is-beta'], 'none' => ['x' => 1]], 'defaultVariant' => 'none'],
        // a reference is an object with exactly one key, a text value: these are not references
        'lookalike' => featureFlagsRegistryFlag('off', ['targeting' => ['if' => [['$ref' => 'is-beta', 'other' => 1], 'on', ['$ref' => 7]]]]),
    ];
    $file = new StubFlagSource('file', 200, 5.0, $flags, failsStartup: true);
    $file->evaluators = [
        'is-beta' => ['in' => [['var' => 'role'], ['beta']]],
        'is-staff' => ['==' => [['var' => 'role'], 'staff']],
        // "vip" sorts after the evaluators it references: references resolve whatever the order
        'vip' => ['or' => [['$ref' => 'is-staff'], ['$ref' => 'is-beta']]],
        'unused' => ['==' => [1, 1]],
    ];
    $registry = featureFlagsRegistry([$file], new Repository(new ArrayStore), $events, $clock);
    $registry->document();

    $file->evaluators['is-beta'] = ['in' => [['var' => 'role'], ['beta', 'tester']]];
    $file->evaluators['unused'] = ['==' => [1, 2]];
    $file->revision = 'r2';
    $clock->now += 5.0;
    $registry->document();

    expect(featureFlagsChanges($events)[1] ?? null)->toBe(['file', ['direct', 'nested']]);
});

it('announces nothing when only an evaluator no flag references changes', function (): void {
    $events = new RecordingApplicationEventPublisher;
    $clock = new FixedClock;
    $file = new StubFlagSource('file', 200, 5.0, ['a' => true], failsStartup: true);
    $file->evaluators = ['unused' => ['==' => [1, 1]]];
    $registry = featureFlagsRegistry([$file], new Repository(new ArrayStore), $events, $clock);
    $registry->document();

    $file->evaluators = ['unused' => ['==' => [1, 2]]];
    $file->revision = 'r2';
    $clock->now += 5.0;

    expect($registry->document()->evaluators)->toBe(['unused' => ['==' => [1, 2]]])
        ->and(featureFlagsChanges($events))->toBe([['startup', ['a']]]);
});

it('announces the keys that inherit a changed document metadata entry', function (): void {
    $events = new RecordingApplicationEventPublisher;
    $clock = new FixedClock;
    $file = new StubFlagSource('file', 200, 5.0, ['inherits' => featureFlagsRegistryFlag(), 'own' => featureFlagsRegistryFlag(fields: ['metadata' => ['owner' => 'payments']])], failsStartup: true);
    $file->metadata = ['owner' => 'platform'];
    $registry = featureFlagsRegistry([$file], new Repository(new ArrayStore), $events, $clock);
    $registry->document();

    $file->metadata = ['owner' => 'checkout'];
    $file->revision = 'r2';
    $clock->now += 5.0;
    $registry->document();

    expect(featureFlagsChanges($events)[1] ?? null)->toBe(['file', ['inherits']]);
});

it('publishes nothing when a recomposition changes nothing', function (): void {
    $events = new RecordingApplicationEventPublisher;
    $clock = new FixedClock;
    $config = new StubFlagSource('config', 100, 0.0, ['a' => true], failsStartup: true);
    $file = new StubFlagSource('file', 200, 5.0, ['a' => featureFlagsRegistryFlag('off', ['targeting' => ['if' => [['$ref' => 'is-beta'], 'on', 'off']]])], failsStartup: true);
    $file->evaluators = ['is-beta' => ['in' => [['var' => 'role'], ['beta']]]];
    $file->metadata = ['owner' => 'platform'];
    $registry = featureFlagsRegistry([$config, $file], new Repository(new ArrayStore), $events, $clock);
    $registry->document();

    $file->revision = 'r2'; // the same document again
    $config->flags = ['a' => false]; // a change the file layer shadows
    $config->revision = 'r2';
    $clock->now += 5.0;
    $registry->document();

    expect($file->loads)->toBe(2)
        ->and($registry->composition()->flag('a')?->layers[0]['definition']->defaultVariant())->toBe('off')
        ->and(featureFlagsChanges($events))->toBe([['startup', ['a']]]);
});

it('announces every change, even a flag flipped back and forth within seconds', function (): void {
    $events = new RecordingApplicationEventPublisher;
    $file = new StubFlagSource('file', 200, 0.0, ['a' => true], failsStartup: true);
    $registry = featureFlagsRegistry([$file], new Repository(new ArrayStore), $events, new FixedClock);
    $registry->document();

    foreach ([false, true, false] as $revision => $value) {
        $file->flags = ['a' => $value];
        $file->revision = 'flip-'.$revision;
        $registry->document();
    }

    expect(featureFlagsChanges($events))->toBe([['startup', ['a']], ['file', ['a']], ['file', ['a']], ['file', ['a']]]);
});

it('names the highest loaded source as the origin when no source moved in this process', function (): void {
    $cache = new Repository(new ArrayStore);
    $events = new RecordingApplicationEventPublisher;
    $config = new StubFlagSource('config', 100, 0.0, ['a' => true], failsStartup: true);
    $store = new StubFlagSource('store', 400, 5.0, ['b' => true]);

    // a rolling deploy that enables the store: an old worker, a new one, then an old one again
    featureFlagsRegistry([$config], $cache, $events, new FixedClock)->document();
    featureFlagsRegistry([$config, $store], $cache, $events, new FixedClock)->document();
    featureFlagsRegistry([$config], $cache, $events, new FixedClock)->document();

    expect(featureFlagsChanges($events))->toBe([['startup', ['a']], ['store', ['b']], ['config', ['b']]]);
});

it('reads numeric-looking flag keys as text everywhere', function (): void {
    $events = new RecordingApplicationEventPublisher;
    $clock = new FixedClock;
    $file = new StubFlagSource('file', 200, 5.0, ['2024' => true, '10' => false, 'b' => true], failsStartup: true);
    $registry = featureFlagsRegistry([$file], new Repository(new ArrayStore), $events, $clock);

    expect($registry->document()->keys())->toBe(['10', '2024', 'b'])
        ->and($registry->composition()->flag('2024')?->origin)->toBe('file')
        ->and($registry->composition()->flag('2024')?->definition->key)->toBe('2024');

    $file->flags = ['2024' => false, '10' => false, 'b' => true];
    $file->revision = 'r2';
    $clock->now += 5.0;
    $registry->document();

    expect(featureFlagsChanges($events))->toBe([['startup', ['10', '2024', 'b']], ['file', ['2024']]]);
});

it('keeps the flags in force when a FeatureFlagsChanged listener throws', function (): void {
    $logger = new RecordingLogger;
    $events = new class implements ApplicationEventPublisher
    {
        public function publish(object $event): void
        {
            throw new RuntimeException('listener exploded');
        }
    };
    $registry = new FlagRegistry([new StubFlagSource('config', 100, 0.0, ['a' => true], failsStartup: true)], new CacheBook(new Repository(new ArrayStore), $logger), $events, $logger, (new FixedClock)(...));

    expect($registry->document()->flag('a')?->defaultVariant())->toBe('on')
        ->and($logger->count('warning', 'listener exploded'))->toBe(1);
});

it('refuses two sources with one name, and a source named like a reserved origin', function (string $second): void {
    $sources = [new StubFlagSource('config', 100, 0.0), new StubFlagSource($second, 200, 0.0)];

    expect(fn () => featureFlagsRegistry($sources, new Repository(new ArrayStore), new RecordingApplicationEventPublisher, new FixedClock))
        ->toThrow(ConfigurationException::class, "[{$second}]");
})->with(['config', FlagRegistry::TEST_LAYER, FlagRegistry::TEST_OVERRIDES_ORIGIN, FlagRegistry::STARTUP_ORIGIN]);

it('warns about an expired flag once a day across every worker', function (): void {
    $cache = new Repository(new ArrayStore);
    $logger = new RecordingLogger;
    $config = new StubFlagSource('config', 100, 0.0, ['old' => [
        'state' => 'ENABLED', 'variants' => ['on' => true, 'off' => false], 'defaultVariant' => 'on', 'metadata' => ['expires' => '2025-01-01'],
    ]], failsStartup: true);

    featureFlagsRegistry([$config], $cache, new RecordingApplicationEventPublisher, new FixedClock, $logger)->start();
    featureFlagsRegistry([$config], $cache, new RecordingApplicationEventPublisher, new FixedClock, $logger)->start();

    expect($logger->count('warning', 'Feature flag [old] expired on 2025-01-01'))->toBe(1)
        ->and($logger->count('warning', 'still defined by [config]'))->toBe(1);
});
