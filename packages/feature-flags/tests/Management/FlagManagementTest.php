<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\FeatureFlags\Context\ApplicationEvaluationContextContributor;
use Firefly\FeatureFlags\Context\EvaluationContextResolver;
use Firefly\FeatureFlags\Definition\FlagDefinitions;
use Firefly\FeatureFlags\Definition\InvalidFlagDefinition;
use Firefly\FeatureFlags\Definition\Json;
use Firefly\FeatureFlags\Evaluation\DefaultFlagdEvaluator;
use Firefly\FeatureFlags\Event\FeatureFlagUpdated;
use Firefly\FeatureFlags\FeatureFlagsSettings;
use Firefly\FeatureFlags\Management\FlagActorSource;
use Firefly\FeatureFlags\Management\FlagManagement;
use Firefly\FeatureFlags\Management\FlagManagementException;
use Firefly\FeatureFlags\Management\ManagementError;
use Firefly\FeatureFlags\Provider\FireflyFlagProvider;
use Firefly\FeatureFlags\Registry\CacheBook;
use Firefly\FeatureFlags\Registry\FlagRegistry;
use Firefly\FeatureFlags\Source\FlagSource;
use Firefly\FeatureFlags\Source\FlagSourceUnavailable;
use Firefly\FeatureFlags\Source\SourceSnapshot;
use Firefly\FeatureFlags\Store\FlagChange;
use Firefly\FeatureFlags\Store\FlagStore;
use Firefly\FeatureFlags\Store\FlagStoreWriter;
use Firefly\FeatureFlags\Store\MemoryFlagStore;
use Firefly\FeatureFlags\Store\StoredFlag;
use Firefly\FeatureFlags\Tests\Support\FixedProvider;
use Firefly\FeatureFlags\Tests\Support\FlagStores;
use Firefly\FeatureFlags\Tests\Support\ManagementFixtures;
use Firefly\FeatureFlags\Tests\Support\RecordingLogger;
use Firefly\Testing\Double\RecordingApplicationEventPublisher;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as Cache;
use Illuminate\Config\Repository;
use Psr\Log\NullLogger;

function featureFlagsManagementRefusal(Closure $operation): ManagementError
{
    try {
        $operation();
    } catch (FlagManagementException $refused) {
        return $refused->error;
    }

    throw new RuntimeException('The operation was not refused.');
}

it('maps every error code to its LaraFly status', function (string $error, int $status): void {
    expect(ManagementError::from($error)->status())->toBe($status);
})->with([
    ['writes-disabled', 403], ['not-writable', 409], ['invalid-definition', 422],
    ['unknown-flag', 404], ['unknown-variant', 422], ['conflict', 409],
    ['bad-request', 400],
]);

it('lists the provider, the enabled sources in precedence order and every flag (I-1)', function (): void {
    $overview = (new ManagementFixtures(ManagementFixtures::flags()))->management->overview();

    expect($overview['provider'])->toBe(['name' => 'firefly', 'status' => 'READY'])
        ->and([$overview['writable'], $overview['writesEnabled']])->toBe([true, true])
        ->and(array_map(static fn (array $source): array => [$source['name'], $source['enabled'], $source['status']], $overview['sources']))
        ->toBe([['config', true, 'UP'], ['store', true, 'UP']])
        ->and($overview['sources'][0]['lastRefresh'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/')
        ->and($overview['sources'][1]['revision'])->toBe('0')
        ->and(array_column($overview['flags'], 'key'))->toBe(['checkout-flow', 'kill-switch', 'legacy-export'])
        ->and($overview['flags'][0])->toMatchArray(['state' => 'ENABLED', 'type' => 'string', 'variants' => ['v1', 'v2'], 'defaultVariant' => 'v1', 'targeting' => true, 'origin' => 'config', 'overrides' => [], 'expired' => false, 'version' => null])
        ->and($overview['flags'][2]['expired'])->toBeTrue()
        ->and(Json::encode($overview['flags'][1]['metadata']))->toBe('{}');
});

it('reports source state from the same refreshed composition as its flag list', function (): void {
    $fixtures = new ManagementFixtures([], refreshInterval: 0.0);
    $fixtures->store->put('checkout', Json::members(Json::decode('{"state":"ENABLED","variants":{"on":true,"off":false},"defaultVariant":"off"}')), 'other-process');

    $overview = $fixtures->management->overview();

    expect($overview['sources'][1]['revision'])->toBe('1')
        ->and(array_column($overview['flags'], 'key'))->toBe(['checkout']);
});

it('describes one flag with its layers, version and history, and refuses an unknown key', function (): void {
    $fixtures = new ManagementFixtures(ManagementFixtures::flags());
    $fixtures->management->apply('kill-switch', ['action' => 'enable'], 'actuator');

    $described = $fixtures->management->describe('kill-switch');

    expect(Json::canonical($described['definition']))->toBe('{"defaultVariant":"off","state":"ENABLED","variants":{"off":false,"on":true}}')
        ->and($described['origin'])->toBe('store')
        ->and(array_column($described['layers'], 'source'))->toBe(['config', 'store'])
        ->and($described['version'])->toBe(1)
        ->and($described['history'])->toBe([['id' => 1, 'action' => 'put', 'actor' => 'actuator', 'changedAt' => '2026-10-01T12:00:00Z']])
        ->and(featureFlagsManagementRefusal(fn () => $fixtures->management->describe('nope')))->toBe(ManagementError::UnknownFlag);
});

it('evaluates with the flag\'s own type, explicit context only, and refuses an unknown key (I-2, I-3)', function (): void {
    $application = new ApplicationEvaluationContextContributor(new Repository(['app' => ['name' => 'shop']]));
    $management = (new ManagementFixtures([
        ...ManagementFixtures::flags(),
        'shop-only' => ['state' => 'ENABLED', 'variants' => ['on' => true, 'off' => false], 'defaultVariant' => 'off',
            'targeting' => ['if' => [['==' => [['var' => 'application'], 'shop']], 'on', null]]],
    ], preview: [$application]))->management;

    expect($management->evaluate('checkout-flow', ['plan' => 'pro']))->toMatchArray(['key' => 'checkout-flow', 'value' => 'v2', 'variant' => 'v2', 'reason' => 'TARGETING_MATCH', 'errorCode' => null])
        ->and($management->evaluate('checkout-flow')['value'])->toBe('v1')
        ->and($management->evaluate('shop-only')['value'])->toBeTrue()
        ->and(featureFlagsManagementRefusal(fn () => $management->evaluate('nope')))->toBe(ManagementError::UnknownFlag);
});

it('uses an empty JSON object for an object-typed preview default', function (): void {
    $management = (new ManagementFixtures([
        'object-flag' => ['state' => 'DISABLED', 'variants' => ['object' => Json::decode('{"name":"value"}')]],
    ]))->management;

    expect(Json::encode($management->evaluate('object-flag')['value']))->toBe('{}');
});

it('copies the effective definition into the store for enable, disable and default-variant', function (): void {
    $fixtures = new ManagementFixtures(ManagementFixtures::flags());

    $enabled = $fixtures->management->apply('kill-switch', ['action' => 'disable'], 'actuator');
    $defaulted = $fixtures->management->apply('checkout-flow', ['action' => 'default-variant', 'variant' => 'v2', 'expectedVersion' => 0], 'actuator');

    /** @var FeatureFlagUpdated $updated */
    $updated = $fixtures->events->ofType(FeatureFlagUpdated::class)[1];

    expect([$enabled['origin'], $enabled['version'], Json::members($enabled['definition'])['state']])->toBe(['store', 1, 'DISABLED'])
        ->and(Json::members($defaulted['definition'])['defaultVariant'])->toBe('v2')
        ->and(Json::encode(Json::members($defaulted['definition'])['targeting']))->toBe('{"if":[{"==":[{"var":"plan"},"pro"]},"v2",null]}')
        ->and([$updated->key, $updated->action, $updated->actor])->toBe(['checkout-flow', 'put', 'actuator']);
});

it('refuses writes with the contract codes', function (): void {
    $fixtures = new ManagementFixtures(ManagementFixtures::flags());
    $fixtures->management->apply('kill-switch', ['action' => 'enable'], 'actuator');
    $management = $fixtures->management;

    expect(featureFlagsManagementRefusal(fn () => $management->apply('kill-switch', ['action' => 'disable', 'expectedVersion' => 7], 'actuator')))->toBe(ManagementError::Conflict)
        ->and(featureFlagsManagementRefusal(fn () => $management->apply('checkout-flow', ['action' => 'default-variant', 'variant' => 'v9'], 'actuator')))->toBe(ManagementError::UnknownVariant)
        ->and(featureFlagsManagementRefusal(fn () => $management->apply('checkout-flow', ['action' => 'default-variant'], 'actuator')))->toBe(ManagementError::BadRequest)
        ->and(featureFlagsManagementRefusal(fn () => $management->apply('x', ['action' => 'put', 'definition' => ['state' => 'ON']], 'actuator')))->toBe(ManagementError::InvalidDefinition)
        ->and(featureFlagsManagementRefusal(fn () => $management->apply('x', ['action' => 'put'], 'actuator')))->toBe(ManagementError::BadRequest)
        ->and(featureFlagsManagementRefusal(fn () => $management->apply('nope', ['action' => 'enable'], 'actuator')))->toBe(ManagementError::UnknownFlag)
        ->and(featureFlagsManagementRefusal(fn () => $management->apply('legacy-export', ['action' => 'delete'], 'actuator')))->toBe(ManagementError::UnknownFlag)
        ->and(featureFlagsManagementRefusal(fn () => $management->apply('kill-switch', [], 'actuator')))->toBe(ManagementError::BadRequest)
        ->and(featureFlagsManagementRefusal(fn () => $management->apply('kill-switch', ['action' => 'rename'], 'actuator')))->toBe(ManagementError::BadRequest)
        ->and(featureFlagsManagementRefusal(fn () => $management->apply('kill-switch', ['action' => 'enable', 'expectedVersion' => -1], 'actuator')))->toBe(ManagementError::BadRequest)
        ->and(featureFlagsManagementRefusal(fn () => $management->apply('kill-switch', ['action' => 'evaluate', 'context' => 'pro'], 'actuator')))->toBe(ManagementError::BadRequest)
        ->and(featureFlagsManagementRefusal(fn () => $management->apply('kill-switch', ['action' => 'evaluate', 'targetingKey' => 7], 'actuator')))->toBe(ManagementError::BadRequest)
        ->and(featureFlagsManagementRefusal(fn () => (new ManagementFixtures(ManagementFixtures::flags(), writes: false))->management->apply('kill-switch', ['action' => 'enable'], 'actuator')))->toBe(ManagementError::WritesDisabled)
        ->and(featureFlagsManagementRefusal(fn () => (new ManagementFixtures(ManagementFixtures::flags(), store: false))->management->apply('kill-switch', ['action' => 'enable'], 'actuator')))->toBe(ManagementError::NotWritable)
        // writes-disabled outranks not-writable: the switch is the first thing an operator must turn.
        ->and(featureFlagsManagementRefusal(fn () => (new ManagementFixtures(ManagementFixtures::flags(), writes: false, store: false))->management->apply('kill-switch', ['action' => 'enable'], 'actuator')))->toBe(ManagementError::WritesDisabled);
});

it('answers deleted:true only when no layer defines the key any more', function (): void {
    $fixtures = new ManagementFixtures(ManagementFixtures::flags());
    $fixtures->management->apply('banner', ['action' => 'put', 'definition' => Json::decode('{"state":"ENABLED","variants":{"none":{}},"defaultVariant":"none"}')], 'actuator');
    $fixtures->management->apply('kill-switch', ['action' => 'enable'], 'actuator');

    expect($fixtures->management->apply('banner', ['action' => 'delete'], 'actuator'))->toBe(['key' => 'banner', 'deleted' => true])
        ->and($fixtures->management->apply('kill-switch', ['action' => 'delete'], 'actuator')['origin'])->toBe('config');
});

it('records the principal when an actor source names one, and the surface\'s fallback otherwise (I-6)', function (): void {
    $principal = new class implements FlagActorSource
    {
        public ?string $name = 'ada';

        public function actor(): ?string
        {
            return $this->name;
        }
    };
    $broken = new class implements FlagActorSource
    {
        public function actor(): ?string
        {
            throw new RuntimeException('security context unavailable');
        }
    };
    $fixtures = new ManagementFixtures(ManagementFixtures::flags(), actors: [$broken, $principal]);

    $fixtures->management->apply('kill-switch', ['action' => 'enable'], 'actuator');
    $principal->name = null;
    $fixtures->management->apply('kill-switch', ['action' => 'disable'], 'admin');

    expect(array_column($fixtures->management->describe('kill-switch')['history'], 'actor'))->toBe(['admin', 'ada']);
});

it('answers an empty read-only overview and boolean previews over an application\'s own provider (I-2)', function (): void {
    $settings = FeatureFlagsSettings::fromConfig(new Config(new Repository(['firefly' => ['feature-flags' => ['enabled' => true, 'management' => ['writes' => true]]]])));
    $management = new FlagManagement($settings, new FixedProvider);

    expect($management->overview())->toBe(['provider' => ['name' => 'fixed-vendor', 'status' => 'READY'], 'writable' => false, 'writesEnabled' => true, 'sources' => [], 'flags' => []])
        ->and($management->evaluate('anything'))->toMatchArray(['value' => true, 'variant' => 'vendor-on', 'reason' => 'STATIC'])
        ->and(featureFlagsManagementRefusal(fn () => $management->describe('anything')))->toBe(ManagementError::UnknownFlag)
        ->and(featureFlagsManagementRefusal(fn () => $management->apply('anything', ['action' => 'enable'], 'actuator')))->toBe(ManagementError::NotWritable);
});

it('uses the five shared management receipt vectors through a real registry and writer', function (): void {
    $contents = file_get_contents(__DIR__.'/../Conformance/management-vectors.json');
    if ($contents === false) {
        throw new RuntimeException('management vectors could not be read');
    }
    /** @var array{cases: list<array{name: string, initialDefinition: mixed, action: string, definition?: mixed, refresh: string, expect: array{body?: mixed, detailDefinition?: mixed}}>} $vectors */
    $vectors = Json::members(Json::decode($contents));
    foreach ($vectors['cases'] as $case) {
        $store = new MemoryFlagStore(FlagStores::clock());
        if ($case['initialDefinition'] !== null) {
            $store->put('checkout', Json::members($case['initialDefinition']), 'setup');
        }
        $source = new class($store) implements FlagSource
        {
            public string $mode = 'accept';

            public function __construct(private readonly MemoryFlagStore $store) {}

            public function name(): string
            {
                return self::STORE;
            }

            public function precedence(): int
            {
                return 400;
            }

            public function refreshInterval(): float
            {
                return 0.0;
            }

            public function failsStartup(): bool
            {
                return false;
            }

            public function reportedRevision(?string $revision): ?string
            {
                return $revision;
            }

            public function load(?string $knownRevision): ?SourceSnapshot
            {
                if ($this->mode === 'fail') {
                    throw new FlagSourceUnavailable('store unavailable');
                }
                if ($this->mode === 'refuse') {
                    throw new InvalidFlagDefinition('checkout', 'state must be ENABLED or DISABLED');
                }
                $revision = (string) $this->store->revision();
                if ($knownRevision === $revision) {
                    return null;
                }
                $flags = [];
                foreach ($this->store->all() as $row) {
                    $flags[$row->key] = $row->definition;
                }

                return new SourceSnapshot(FlagDefinitions::parseDocument(['flags' => Json::object($flags)]), $revision);
            }
        };
        $events = new RecordingApplicationEventPublisher;
        $registry = new FlagRegistry([$source], new CacheBook(new Cache(new ArrayStore), new NullLogger), $events);
        $registry->start();
        $settings = FeatureFlagsSettings::fromConfig(new Config(new Repository(['firefly' => ['feature-flags' => ['enabled' => true, 'management' => ['writes' => true]]]])));
        $management = new FlagManagement($settings, new FireflyFlagProvider($registry, new DefaultFlagdEvaluator), $registry, new FlagStoreWriter($store, $registry, $events), new EvaluationContextResolver);
        $source->mode = $case['refresh'];
        $body = ['action' => $case['action']];
        if (isset($case['definition'])) {
            $body['definition'] = $case['definition'];
        }
        $receipt = $management->apply('checkout', $body, 'actuator');
        if (isset($case['expect']['body'])) {
            expect(Json::canonical($receipt))->toBe(Json::canonical($case['expect']['body']), $case['name']);
        } elseif (array_key_exists('detailDefinition', $case['expect'])) {
            expect(Json::canonical($receipt['definition']))->toBe(Json::canonical($case['expect']['detailDefinition']), $case['name']);
        } else {
            throw new RuntimeException('management vector has no expected receipt or detail');
        }
    }
});

it('keeps accepted writes and visible reads when store diagnostics fail', function (): void {
    $fixture = new ManagementFixtures(ManagementFixtures::flags());
    $diagnosticStore = new class($fixture->store) implements FlagStore
    {
        public function __construct(private readonly MemoryFlagStore $delegate) {}

        public function all(): array
        {
            throw new RuntimeException('diagnostic all failed');
        }

        public function get(string $key): ?StoredFlag
        {
            return $this->delegate->get($key);
        }

        public function revision(): int
        {
            return $this->delegate->revision();
        }

        public function put(string $key, array $definition, ?string $actor, ?int $expectedVersion = null): FlagChange
        {
            return $this->delegate->put($key, $definition, $actor, $expectedVersion);
        }

        public function delete(string $key, ?string $actor, ?int $expectedVersion = null): ?FlagChange
        {
            return $this->delegate->delete($key, $actor, $expectedVersion);
        }

        public function history(string $key, int $limit = 50): array
        {
            throw new RuntimeException('diagnostic history failed');
        }
    };
    $settings = FeatureFlagsSettings::fromConfig(new Config(new Repository(['firefly' => ['feature-flags' => ['enabled' => true, 'management' => ['writes' => true]]]])));
    $warnings = new RecordingLogger;
    $management = new FlagManagement($settings, new FireflyFlagProvider($fixture->registry, new DefaultFlagdEvaluator), $fixture->registry, new FlagStoreWriter($diagnosticStore, $fixture->registry, $fixture->events), logger: $warnings);

    $written = $management->apply('kill-switch', ['action' => 'enable'], 'actuator');

    expect($written['origin'])->toBe('store')
        ->and($written['history'])->toBe([])
        ->and(array_column($management->overview()['flags'], 'key'))->toContain('kill-switch')
        ->and($warnings->count('warning', 'diagnostic history failed'))->toBe(1)
        ->and($warnings->count('warning', 'diagnostic all failed'))->toBe(1);
});
