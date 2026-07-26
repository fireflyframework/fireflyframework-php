<?php

declare(strict_types=1);

namespace Firefly\Actuator;

use Firefly\Actuator\Endpoint\ExposureModel;
use Firefly\Config\Config;
use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Configuration;
use Firefly\Container\Attributes\Order;
use Firefly\Context\Condition\Attributes\ConditionalOnMissingBean;

/**
 * Actuator's config-derived bean source. The master gate firefly.management.enabled (default true) is enforced in
 * ActuatorRouteRegistrar (it registers no routes when off), NOT here — the beans are harmless without routes. The
 * framework infrastructure collectors (ActuatorRegistry, Health/InfoContributorRegistry, StatusAggregator) are
 * bound imperatively in ActuatorWiringProvider (the WebServiceProvider idiom), so this class owns only the one
 * config-derived value bean. #[ConditionalOnMissingBean] lets an app override it. Mirrors SecurityAutoConfiguration.
 */
#[Configuration]
#[Order(1000)]
final class ActuatorAutoConfiguration
{
    #[Bean]
    #[ConditionalOnMissingBean(ExposureModel::class)]
    public function exposureModel(Config $config): ExposureModel
    {
        return ExposureModel::fromConfig($config);
    }
}
