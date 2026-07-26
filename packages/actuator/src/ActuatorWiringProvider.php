<?php

declare(strict_types=1);

namespace Firefly\Actuator;

use Firefly\Actuator\Boot\ActuatorRouteRegistrar;
use Firefly\Actuator\Boot\HealthContributorRegistrar;
use Firefly\Actuator\Boot\InfoContributorRegistrar;
use Firefly\Actuator\Endpoint\ActuatorRegistry;
use Firefly\Actuator\Health\HealthContributorRegistry;
use Firefly\Actuator\Health\StatusAggregator;
use Firefly\Actuator\Info\InfoContributorRegistry;
use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\FireflyServiceProvider;

/**
 * The boot-pass + default-binding half of firefly/actuator (cannot ride on ActuatorServiceProvider —
 * AutoConfiguration's final register() records candidacy only). Binds ActuatorRegistry (the id → endpoint map
 * ActuatorRouteRegistrar populates and the request-time actions read), HealthContributorRegistry (the name →
 * HealthIndicator map HealthContributorRegistrar populates and HealthEndpoint reads at request time),
 * StatusAggregator (most-severe-wins health aggregation), and InfoContributorRegistry (the list<InfoContributor>
 * InfoContributorRegistrar populates and InfoEndpoint deep-merges at request time) behind bound() guards — the
 * exact WebServiceProvider idiom — then contributes HealthContributorRegistrar (order 10), InfoContributorRegistrar
 * (order 20, both before route mounting), and ActuatorRouteRegistrar (order 50) via passes(). Both this and
 * ActuatorServiceProvider are in extra.laravel.providers.
 *
 * ExposureModel is DELIBERATELY NOT bound here anymore: ActuatorAutoConfiguration now owns it as a config-derived
 * #[Bean] (#[ConditionalOnMissingBean(ExposureModel::class)]), bound into the container at BootPhase::
 * FlushDefinitions (650) — strictly BEFORE ActuatorRouteRegistrar's own phase (WiringPasses, 1000) resolves it.
 * That Bean is only reachable because ActuatorServiceProvider (always registered alongside this provider) records
 * its compiled manifest candidacy at register() time regardless of app scan config, so the Bean survives even in
 * a bare-skeleton boot. Keeping a second bound()-guarded default here would be redundant dead weight, not a safety
 * net: ContainerRegistrar::register() always calls Container::singleton() unconditionally for a surviving #[Bean],
 * which unconditionally rebinds (and clears any cached instance for) whatever this provider bound earlier anyway.
 */
final class ActuatorWiringProvider extends FireflyServiceProvider
{
    public function register(): void
    {
        if (! $this->app->bound(ActuatorRegistry::class)) {
            $this->app->singleton(ActuatorRegistry::class, static fn (): ActuatorRegistry => new ActuatorRegistry);
        }

        if (! $this->app->bound(HealthContributorRegistry::class)) {
            $this->app->singleton(HealthContributorRegistry::class, static fn (): HealthContributorRegistry => new HealthContributorRegistry);
        }

        if (! $this->app->bound(StatusAggregator::class)) {
            $this->app->singleton(StatusAggregator::class, static fn (): StatusAggregator => new StatusAggregator);
        }

        if (! $this->app->bound(InfoContributorRegistry::class)) {
            $this->app->singleton(InfoContributorRegistry::class, static fn (): InfoContributorRegistry => new InfoContributorRegistry);
        }

        parent::register();
    }

    /**
     * @return list<BootPass>
     */
    public function passes(): array
    {
        return [new HealthContributorRegistrar, new InfoContributorRegistrar, new ActuatorRouteRegistrar];
    }
}
