<?php

declare(strict_types=1);

namespace Firefly\Actuator;

use Firefly\Actuator\Boot\ActuatorRouteRegistrar;
use Firefly\Actuator\Boot\HealthContributorRegistrar;
use Firefly\Actuator\Boot\InfoContributorRegistrar;
use Firefly\Actuator\Endpoint\ActuatorRegistry;
use Firefly\Actuator\Endpoint\ExposureModel;
use Firefly\Actuator\Health\HealthContributorRegistry;
use Firefly\Actuator\Health\StatusAggregator;
use Firefly\Actuator\Info\InfoContributorRegistry;
use Firefly\Config\Config;
use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\FireflyServiceProvider;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;

/**
 * The boot-pass + default-binding half of firefly/actuator (cannot ride on ActuatorServiceProvider —
 * AutoConfiguration's final register() records candidacy only). Binds ActuatorRegistry (the id → endpoint map
 * ActuatorRouteRegistrar populates and the request-time actions read), ExposureModel (the include/exclude/
 * base-path exposure policy), HealthContributorRegistry (the name → HealthIndicator map HealthContributorRegistrar
 * populates and HealthEndpoint reads at request time), StatusAggregator (most-severe-wins health aggregation), and
 * InfoContributorRegistry (the list<InfoContributor> InfoContributorRegistrar populates and InfoEndpoint deep-merges
 * at request time) behind bound() guards — the exact WebServiceProvider idiom — then contributes
 * HealthContributorRegistrar (order 10), InfoContributorRegistrar (order 20, both before route mounting), and
 * ActuatorRouteRegistrar (order 50) via passes(). Both this and ActuatorServiceProvider are in extra.laravel.providers.
 *
 * ExposureModel MUST be bound here, not left to container autowiring: unlike Config (whose sole constructor
 * param is the `Illuminate\Contracts\Config\Repository` interface, resolvable via Illuminate\Foundation\
 * Application's core container alias for the 'config' binding), ExposureModel's constructor takes plain
 * `array $include, array $exclude, string $basePath` — no class-typed parameter for the container to reflect
 * a binding from. `$container->make(ExposureModel::class)` without an explicit binding throws
 * BindingResolutionException ("Unresolvable dependency… array $include") — verified directly: this exact
 * failure surfaced in the pre-existing PackageBootTest the moment ActuatorRouteRegistrar (which resolves
 * ExposureModel::class at WiringPasses to read basePath) was wired into passes() below. No #[Bean]-producing
 * ActuatorAutoConfiguration exists yet (that lands in a later M12 task), so this bound()-guarded singleton is
 * the only seam available today; a later ActuatorAutoConfiguration's own #[ConditionalOnMissingBean] bean, if
 * one is ever added, will simply win over this default without any change needed here.
 */
final class ActuatorWiringProvider extends FireflyServiceProvider
{
    public function register(): void
    {
        if (! $this->app->bound(ActuatorRegistry::class)) {
            $this->app->singleton(ActuatorRegistry::class, static fn (): ActuatorRegistry => new ActuatorRegistry);
        }

        if (! $this->app->bound(ExposureModel::class)) {
            $this->app->singleton(ExposureModel::class, static function (Container $app): ExposureModel {
                /** @var Repository $repository */
                $repository = $app->make('config');

                return ExposureModel::fromConfig(new Config($repository));
            });
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
