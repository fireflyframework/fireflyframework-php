<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Management;

use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Configuration;
use Firefly\Container\Attributes\Order;
use Firefly\Container\Container as FireflyContainer;
use Firefly\Context\Condition\Attributes\ConditionalOnMissingBean;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Firefly\FeatureFlags\Context\ApplicationEvaluationContextContributor;
use Firefly\FeatureFlags\Context\EvaluationContextContributor;
use Firefly\FeatureFlags\Context\EvaluationContextResolver;
use Firefly\FeatureFlags\FeatureFlagsSettings;
use Firefly\FeatureFlags\Registry\FlagRegistry;
use Firefly\FeatureFlags\Store\FlagStoreWriter;
use OpenFeature\interfaces\provider\Provider;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * FlagManagement, for the actuator endpoint, the admin page and `firefly:flags`. Present whenever the subsystem
 * is enabled — with the shipped provider (registry, optional store) or with an application's own provider, where
 * it answers an empty, read-only overview and boolean previews. The registry and the writer are optional beans,
 * so they are looked up rather than injected (a nullable parameter would be autowired, not left null).
 */
#[Configuration]
#[Order(700)]
final class FeatureFlagsManagementConfiguration
{
    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.feature-flags.enabled', havingValue: 'true')]
    #[ConditionalOnMissingBean(FlagManagement::class)]
    public function flagManagement(FeatureFlagsSettings $settings, Provider $provider, FireflyContainer $beans, ?LoggerInterface $logger = null): FlagManagement
    {
        $registry = $beans->has(FlagRegistry::class) ? $beans->get(FlagRegistry::class) : null;
        $writer = $beans->has(FlagStoreWriter::class) ? $beans->get(FlagStoreWriter::class) : null;

        $preview = array_values(array_filter(
            $beans->getAll(EvaluationContextContributor::class),
            static fn (object $contributor): bool => $contributor instanceof ApplicationEvaluationContextContributor,
        ));
        $actors = array_values(array_filter(
            $beans->getAll(FlagActorSource::class),
            static fn (object $source): bool => $source instanceof FlagActorSource,
        ));

        /** @var list<ApplicationEvaluationContextContributor> $preview */
        /** @var list<FlagActorSource> $actors */
        return new FlagManagement(
            $settings,
            $provider,
            $registry instanceof FlagRegistry ? $registry : null,
            $writer instanceof FlagStoreWriter ? $writer : null,
            new EvaluationContextResolver($preview),
            $actors,
            logger: $logger ?? new NullLogger,
        );
    }
}
