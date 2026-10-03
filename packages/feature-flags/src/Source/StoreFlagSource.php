<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Source;

use Firefly\Container\Attributes\Component;
use Firefly\Container\Container;
use Firefly\Context\Condition\Attributes\ConditionalOnMissingBean;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Firefly\FeatureFlags\Definition\FlagDefinitions;
use Firefly\FeatureFlags\Definition\Json;
use Firefly\FeatureFlags\FeatureFlagsSettings;
use Firefly\FeatureFlags\Store\FlagStore;
use Firefly\FeatureFlags\Store\TransactionAwareFlagStore;
use OpenFeature\interfaces\provider\Provider;
use Throwable;

/** The writable layer, polled by revision and resolved only when checked. */
#[Component]
#[ConditionalOnProperty(name: 'firefly.feature-flags.enabled', havingValue: 'true')]
#[ConditionalOnProperty(name: 'firefly.feature-flags.sources.store.enabled', havingValue: 'true')]
#[ConditionalOnMissingBean(Provider::class)]
final class StoreFlagSource implements FlagSource
{
    public function __construct(
        private readonly FeatureFlagsSettings $settings,
        private readonly Container $beans,
    ) {}

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
        return $this->settings->store->refreshInterval;
    }

    public function failsStartup(): bool
    {
        return false;
    }

    public function reportedRevision(?string $revision): ?string
    {
        return $revision;
    }

    public function transactionActive(): bool
    {
        try {
            $store = $this->store();
        } catch (Throwable) {
            return false;
        }

        return $store instanceof TransactionAwareFlagStore && $store->transactionActive();
    }

    public function load(?string $knownRevision): ?SourceSnapshot
    {
        $store = $this->store();
        if ($store instanceof TransactionAwareFlagStore && $store->transactionActive()) {
            return null;
        }

        try {
            $revision = (string) $store->revision();
            if ($revision === $knownRevision) {
                return null;
            }

            $flags = [];
            foreach ($store->all() as $stored) {
                $flags[$stored->key] = $stored->definition;
            }
        } catch (Throwable $failure) {
            throw new FlagSourceUnavailable('The flag store cannot be read: '.$failure->getMessage(), 0, $failure);
        }

        return new SourceSnapshot(FlagDefinitions::parseDocument(['flags' => Json::object($flags)]), $revision);
    }

    private function store(): FlagStore
    {
        $store = $this->beans->get(FlagStore::class);
        if (! $store instanceof FlagStore) {
            throw new FlagSourceUnavailable('No FlagStore bean is configured.');
        }

        return $store;
    }
}
