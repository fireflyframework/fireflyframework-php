<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags;

use Firefly\Config\Config;
use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Configuration;
use Firefly\Container\Attributes\Order;
use Firefly\Context\Condition\Attributes\ConditionalOnMissingBean;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;

/**
 * The core feature-flag wiring. #[Order(700)] places it after Observability (500), so that package's
 * FeatureFlagMetrics backs this one's NoOp off, and after every user definition, so a bean of the
 * application's own wins. Every bean is gated on firefly.feature-flags.enabled.
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
}
