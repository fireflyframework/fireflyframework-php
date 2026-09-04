<?php

declare(strict_types=1);

namespace Firefly\Actuator;

use Firefly\Actuator\Endpoint\ExposureModel;
use Firefly\Actuator\Server\ManagementPortGuard;
use Firefly\Actuator\Server\ManagementServerSettings;
use Firefly\Config\Config;
use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Configuration;
use Firefly\Container\Attributes\Order;
use Firefly\Context\Condition\Attributes\ConditionalOnMissingBean;

/**
 * Actuator's config-derived bean source. The master gate firefly.management.enabled (default true) is enforced in
 * ActuatorRouteRegistrar (it registers no routes when off), NOT here — the beans are harmless without routes. The
 * framework infrastructure collectors (ActuatorRegistry, Health/InfoContributorRegistry, StatusAggregator) are
 * bound imperatively in ActuatorWiringProvider (the WebServiceProvider idiom), so this class owns only the
 * config-derived value beans. #[ConditionalOnMissingBean] lets an app override each. Mirrors
 * SecurityAutoConfiguration.
 *
 * ManagementServerSettings and ManagementPortGuard live here, not in ActuatorWiringProvider, for the reason
 * ExposureModel does: they are derived from Config, so they must be resolved at BootPhase::FlushDefinitions (650),
 * strictly before ActuatorRouteRegistrar (WiringPasses, 1000) reads the mount path off them. They are bound
 * UNCONDITIONALLY — deliberately NOT behind firefly.management.enabled. The master gate only stops routes being
 * mounted; the beans themselves are inert without routes, and firefly/admin resolves ManagementPortGuard to guard
 * its own dashboard whether or not the JSON actuator mounted anything.
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

    #[Bean]
    #[ConditionalOnMissingBean(ManagementServerSettings::class)]
    public function managementServerSettings(Config $config): ManagementServerSettings
    {
        return ManagementServerSettings::fromConfig($config);
    }

    #[Bean]
    #[ConditionalOnMissingBean(ManagementPortGuard::class)]
    public function managementPortGuard(ManagementServerSettings $settings): ManagementPortGuard
    {
        return new ManagementPortGuard($settings);
    }
}
