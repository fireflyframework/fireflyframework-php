<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Tests\Support;

use Firefly\Config\Config;
use Firefly\Container\Container as FireflyContainer;
use Firefly\Container\Scanner\ComponentManifest;
use Firefly\FeatureFlags\Context\EvaluationContextContributor;
use Firefly\FeatureFlags\Context\EvaluationContextResolver;
use Firefly\FeatureFlags\Evaluation\DefaultFlagdEvaluator;
use Firefly\FeatureFlags\FeatureFlagsSettings;
use Firefly\FeatureFlags\Management\FlagActorSource;
use Firefly\FeatureFlags\Management\FlagManagement;
use Firefly\FeatureFlags\Provider\FireflyFlagProvider;
use Firefly\FeatureFlags\Registry\CacheBook;
use Firefly\FeatureFlags\Registry\FlagRegistry;
use Firefly\FeatureFlags\Source\ConfigFlagSource;
use Firefly\FeatureFlags\Source\StoreFlagSource;
use Firefly\FeatureFlags\Store\FlagStore;
use Firefly\FeatureFlags\Store\FlagStoreWriter;
use Firefly\FeatureFlags\Store\MemoryFlagStore;
use Firefly\Testing\Double\RecordingApplicationEventPublisher;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as Cache;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Psr\Log\NullLogger;

/** FlagManagement over a real registry: config flags + a memory store (clock FlagStores::clock()), today 2026-10-01. */
final class ManagementFixtures
{
    public readonly FlagManagement $management;

    public readonly MemoryFlagStore $store;

    public readonly RecordingApplicationEventPublisher $events;

    public readonly FlagRegistry $registry;

    /**
     * @param  array<array-key, mixed>  $flags  firefly.feature-flags.flags (shorthand allowed)
     * @param  list<FlagActorSource>  $actors
     * @param  list<EvaluationContextContributor>  $preview
     */
    public function __construct(array $flags, bool $writes = true, bool $store = true, array $actors = [], array $preview = [], float $refreshInterval = 5.0)
    {
        $settings = FeatureFlagsSettings::fromConfig(new Config(new Repository(['firefly' => ['feature-flags' => [
            'enabled' => true,
            'flags' => $flags,
            'sources' => ['store' => ['enabled' => $store, 'driver' => 'memory', 'refresh-interval' => $refreshInterval]],
            'management' => ['writes' => $writes],
        ]]])));

        $this->store = new MemoryFlagStore(FlagStores::clock());
        $illuminate = new Container;
        $illuminate->instance(FlagStore::class, $this->store);

        $sources = [new ConfigFlagSource($settings)];
        if ($store) {
            $sources[] = new StoreFlagSource($settings, new FireflyContainer($illuminate, new ComponentManifest([])));
        }

        $this->events = new RecordingApplicationEventPublisher;
        $this->registry = new FlagRegistry($sources, new CacheBook(new Cache(new ArrayStore), new NullLogger), $this->events);
        $this->registry->start();

        $this->management = new FlagManagement(
            $settings,
            new FireflyFlagProvider($this->registry, new DefaultFlagdEvaluator),
            $this->registry,
            $store ? new FlagStoreWriter($this->store, $this->registry, $this->events) : null,
            new EvaluationContextResolver($preview),
            $actors,
            static fn (): string => '2026-10-01',
        );
    }

    /** @return array<array-key, mixed> the flags most tests start from */
    public static function flags(): array
    {
        return [
            'kill-switch' => false,
            'checkout-flow' => ['state' => 'ENABLED', 'variants' => ['v1' => 'v1', 'v2' => 'v2'], 'defaultVariant' => 'v1',
                'targeting' => ['if' => [['==' => [['var' => 'plan'], 'pro']], 'v2', null]], 'metadata' => ['owner' => 'payments']],
            'legacy-export' => ['state' => 'ENABLED', 'variants' => ['on' => true, 'off' => false], 'defaultVariant' => 'on',
                'metadata' => ['expires' => '2025-01-01']],
        ];
    }
}
