<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Store;

use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Configuration;
use Firefly\Container\Attributes\Order;
use Firefly\Context\Condition\Attributes\ConditionalOnBean;
use Firefly\Context\Condition\Attributes\ConditionalOnMissingBean;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Firefly\Context\Event\ApplicationEventPublisher;
use Firefly\FeatureFlags\FeatureFlagsSettings;
use Firefly\FeatureFlags\Registry\FlagRegistry;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\ConnectionResolverInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/** Wires the optional writable layer after the core registry has been selected. */
#[Configuration]
#[Order(700)]
final class FeatureFlagStoreConfiguration
{
    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.feature-flags.enabled', havingValue: 'true')]
    #[ConditionalOnProperty(name: 'firefly.feature-flags.sources.store.enabled', havingValue: 'true')]
    #[ConditionalOnBean(FlagRegistry::class)]
    #[ConditionalOnMissingBean(FlagStore::class)]
    public function flagStore(FeatureFlagsSettings $settings, Container $app): FlagStore
    {
        if ($settings->store->driver === 'memory') {
            return new MemoryFlagStore;
        }

        // Resolve only for the database driver: a nullable resolver parameter is still made by Laravel.
        $databases = $app->bound('db') ? $app->make('db') : null;
        if (! $databases instanceof ConnectionResolverInterface) {
            throw new ConfigurationException('firefly.feature-flags.sources.store.driver is database but no database connection resolver (db) is bound.');
        }

        return new DatabaseFlagStore($databases->connection($settings->store->connection));
    }

    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.feature-flags.enabled', havingValue: 'true')]
    #[ConditionalOnProperty(name: 'firefly.feature-flags.sources.store.enabled', havingValue: 'true')]
    #[ConditionalOnBean(FlagRegistry::class)]
    #[ConditionalOnMissingBean(FlagStoreWriter::class)]
    public function flagStoreWriter(FlagStore $store, FlagRegistry $registry, ApplicationEventPublisher $events, ?LoggerInterface $logger = null): FlagStoreWriter
    {
        return new FlagStoreWriter($store, $registry, $events, $logger ?? new NullLogger);
    }
}
