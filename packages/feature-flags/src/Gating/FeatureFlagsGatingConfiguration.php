<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Gating;

use Firefly\Config\Config;
use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Configuration;
use Firefly\Container\Attributes\Order;
use Firefly\Container\Container as FireflyContainer;
use Firefly\Context\Condition\Attributes\ConditionalOnMissingBean;
use Illuminate\Contracts\Container\Container;

/** Gate beans stay registered even when feature flags are disabled, so compiled gates fail closed. */
#[Configuration]
#[Order(700)]
final class FeatureFlagsGatingConfiguration
{
    #[Bean]
    #[ConditionalOnMissingBean(FeatureFlagGate::class)]
    public function featureFlagGate(FireflyContainer $beans, Config $config): FeatureFlagGate
    {
        return new FeatureFlagGate($beans, $config);
    }

    #[Bean]
    #[ConditionalOnMissingBean(RouteGateDecisions::class)]
    public function routeGateDecisions(Container $app): RouteGateDecisions
    {
        return new RouteGateDecisions($app);
    }

    #[Bean]
    #[ConditionalOnMissingBean(FeatureFlagMethodInterceptor::class)]
    public function featureFlagMethodInterceptor(FeatureFlagGate $gate, RouteGateDecisions $decisions): FeatureFlagMethodInterceptor
    {
        return new FeatureFlagMethodInterceptor($gate, $decisions);
    }
}
