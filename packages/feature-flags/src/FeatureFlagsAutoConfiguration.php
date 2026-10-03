<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags;

use Firefly\Config\Config;
use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Configuration;
use Firefly\Container\Attributes\Order;
use Firefly\Container\Container as FireflyContainer;
use Firefly\Context\Condition\Attributes\ConditionalOnMissingBean;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Firefly\Context\Event\ApplicationEventPublisher;
use Firefly\FeatureFlags\Context\EvaluationContextContributor;
use Firefly\FeatureFlags\Context\EvaluationContextResolver;
use Firefly\FeatureFlags\Evaluation\FlagdEvaluator;
use Firefly\FeatureFlags\Provider\FireflyFlagProvider;
use Firefly\FeatureFlags\Registry\CacheBook;
use Firefly\FeatureFlags\Registry\FlagDocumentSource;
use Firefly\FeatureFlags\Registry\FlagRegistry;
use Firefly\FeatureFlags\Source\FlagSource;
use Firefly\FeatureFlags\Telemetry\ExposureEventHook;
use Firefly\FeatureFlags\Telemetry\FeatureFlagMetrics;
use Firefly\FeatureFlags\Telemetry\MetricsHook;
use Firefly\FeatureFlags\Telemetry\NoOpFeatureFlagMetrics;
use Illuminate\Contracts\Cache\Repository;
use OpenFeature\interfaces\flags\Client;
use OpenFeature\interfaces\provider\Provider;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Core feature-flag wiring. Observability (order 500) and application beans take precedence. An
 * application Provider switches off Firefly's registry and sources while retaining the facade and hooks.
 * Collections come from scanned components through the Firefly container's getAll().
 */
#[Configuration]
#[Order(700)]
final class FeatureFlagsAutoConfiguration
{
    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.feature-flags.enabled', havingValue: 'true')]
    #[ConditionalOnMissingBean(FeatureFlagsSettings::class)]
    public function featureFlagsSettings(Config $config): FeatureFlagsSettings
    {
        return FeatureFlagsSettings::fromConfig($config);
    }

    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.feature-flags.enabled', havingValue: 'true')]
    #[ConditionalOnMissingBean(FeatureFlagMetrics::class)]
    public function featureFlagMetrics(): FeatureFlagMetrics
    {
        return new NoOpFeatureFlagMetrics;
    }

    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.feature-flags.enabled', havingValue: 'true')]
    #[ConditionalOnMissingBean(Provider::class)]
    #[ConditionalOnMissingBean(FlagRegistry::class)]
    public function flagRegistry(FireflyContainer $beans, Repository $cache, ApplicationEventPublisher $events, ?LoggerInterface $logger = null): FlagRegistry
    {
        $logger ??= new NullLogger;
        $sources = array_values(array_filter($beans->getAll(FlagSource::class), static fn (object $source): bool => $source instanceof FlagSource));

        /** @var list<FlagSource> $sources */
        return new FlagRegistry($sources, new CacheBook($cache, $logger), $events, $logger);
    }

    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.feature-flags.enabled', havingValue: 'true')]
    #[ConditionalOnMissingBean(Provider::class)]
    public function openFeatureProvider(FlagRegistry $registry, FlagdEvaluator $evaluator, ?LoggerInterface $logger = null): Provider
    {
        $provider = new FireflyFlagProvider($registry, $evaluator);
        if ($logger !== null) {
            $provider->setLogger($logger);
        }

        return $provider;
    }

    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.feature-flags.enabled', havingValue: 'true')]
    #[ConditionalOnMissingBean(EvaluationContextResolver::class)]
    public function evaluationContextResolver(FireflyContainer $beans, ?LoggerInterface $logger = null): EvaluationContextResolver
    {
        $contributors = array_values(array_filter($beans->getAll(EvaluationContextContributor::class), static fn (object $contributor): bool => $contributor instanceof EvaluationContextContributor));

        /** @var list<EvaluationContextContributor> $contributors */
        return new EvaluationContextResolver($contributors, $logger ?? new NullLogger);
    }

    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.feature-flags.enabled', havingValue: 'true')]
    #[ConditionalOnMissingBean(FeatureFlags::class)]
    public function featureFlags(
        Provider $provider,
        EvaluationContextResolver $context,
        FeatureFlagMetrics $metrics,
        ApplicationEventPublisher $events,
        FeatureFlagsSettings $settings,
        FireflyContainer $beans,
        ?LoggerInterface $logger = null,
    ): FeatureFlags {
        $logger ??= new NullLogger;
        $hooks = [new MetricsHook($metrics, $logger)];
        if ($settings->publishEvaluations) {
            $hooks[] = new ExposureEventHook($events, $logger);
        }

        $registry = $provider instanceof FireflyFlagProvider && $beans->has(FlagRegistry::class)
            ? $beans->get(FlagRegistry::class)
            : null;

        return new FeatureFlags($provider, $context, $hooks, $registry instanceof FlagDocumentSource ? $registry : null);
    }

    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.feature-flags.enabled', havingValue: 'true')]
    #[ConditionalOnMissingBean(Client::class)]
    public function featureFlagsClient(FeatureFlags $flags): Client
    {
        return $flags->client();
    }
}
