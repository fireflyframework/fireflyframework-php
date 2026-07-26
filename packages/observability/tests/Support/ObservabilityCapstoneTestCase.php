<?php

declare(strict_types=1);

namespace Firefly\Observability\Tests\Support;

use Firefly\Actuator\ActuatorServiceProvider;
use Firefly\Actuator\ActuatorWiringProvider;
use Firefly\AutoConfigure\FireflyAutoConfigureServiceProvider;
use Firefly\Cqrs\CqrsServiceProvider;
use Firefly\Cqrs\CqrsWiringProvider;
use Firefly\Observability\ObservabilityServiceProvider;
use Firefly\Observability\ObservabilityWiringProvider;
use Firefly\Resilience\ResilienceServiceProvider;
use Firefly\Scheduling\Schedule\ScheduledManifest;
use Firefly\Validation\ValidationServiceProvider;
use Firefly\Web\WebServiceProvider;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use LogicException;
use Orchestra\Testbench\TestCase;

/**
 * Boots actuator + observability over a real Web layer so /actuator/prometheus scrapes through the HTTP kernel.
 *
 * NOTE (brief-test fix, three gaps beyond the brief's literal draft):
 *
 * (1) CqrsServiceProvider/CqrsWiringProvider are ADDED (the brief's draft omitted them). Proving RISK #4 end-to-end
 * (§7) means proving `MeterRegistryCqrsMetrics` actually wins over the M10 `NoOpCqrsMetrics` INSIDE a real HTTP
 * boot, not merely the container-level RealProviderBootTest — which needs the real `firefly/cqrs` stack present so
 * there is an M10 default to beat in the first place.
 *
 * (2) ResilienceServiceProvider is ADDED (also omitted by the brief) so the capstone can trip a real
 * `ResilienceRegistry`-backed `CircuitBreaker` and prove `MeterBindingsPass`'s CB-state gauge (§7 risk 3) reaches
 * the same `/actuator/prometheus` scrape. `cache.default => array` + a `circuit-breaker.demo` instance with
 * `failure-threshold => 1` (below) make one failing call enough to flip the breaker OPEN, keeping the test fast and
 * deterministic — no need to exhaust the default 5-failure window.
 *
 * (3) `defineEnvironment()` binds an empty `ScheduledManifest` — the SAME brief-test gap
 * `ActuatorCapstoneTestCase`/`RealProviderBootTest` (T10/T11) already documented and fixed: `ScheduledTasksEndpoint`
 * (`#[ConditionalOnClass(ScheduledManifest::class)]`) survives condition filtering because `firefly/scheduling` is
 * a hard composer dependency of `firefly/actuator` (the class always autoloads), so it is eagerly resolved at
 * `BootPhase::EagerSingletons` (900) and needs `ScheduledManifest` already bound. No Scheduling provider is
 * registered here (out of scope for an observability-focused capstone), so bind a bare manifest directly, exactly
 * as `ActuatorCapstoneTestCase` does.
 *
 * (4) The brief's literal test bodies reach into `$this->app` directly, but that property is `protected` on the
 * base `Orchestra\Testbench\TestCase` — PHPStan (max) correctly flags cross-scope protected access from a Pest
 * `it()` closure even though Pest rebinds `$this` to this instance at runtime. Every OTHER capstone in this monorepo
 * (cqrs/data/eda/messaging/scheduling) avoids the exact same PHPStan error via a small `public` accessor; this class
 * follows that established idiom instead of the brief's literal `$this->app`.
 */
abstract class ObservabilityCapstoneTestCase extends TestCase
{
    /**
     * A typed, narrowed accessor over the inherited (untyped, protected) `$app` property that only holds a real
     * Application once setUp() has run. Mirrors SchedulingCapstoneTestCase::capstoneApp() — gives Pest test
     * closures a real, non-nullable Application without a PHPStan protected-property violation.
     */
    public function capstoneApp(): Application
    {
        if (! $this->app instanceof Application) {
            throw new LogicException('The application has not been booted yet — call this from within a test.');
        }

        return $this->app;
    }

    /**
     * @param  Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            FireflyAutoConfigureServiceProvider::class,
            ValidationServiceProvider::class,
            WebServiceProvider::class,
            ActuatorServiceProvider::class,
            ActuatorWiringProvider::class,
            CqrsServiceProvider::class,
            CqrsWiringProvider::class,
            ResilienceServiceProvider::class,
            ObservabilityServiceProvider::class,
            ObservabilityWiringProvider::class,
        ];
    }

    /**
     * @param  Application  $app
     */
    protected function resolveApplicationConfiguration($app): void
    {
        parent::resolveApplicationConfiguration($app);

        /** @var Repository $config */
        $config = $app->make('config');
        $config->set('cache.default', 'array');
        $config->set('firefly.management.enabled', true);
        $config->set('firefly.management.endpoints.web.exposure.include', 'health,info,prometheus,metrics');
        $config->set('firefly.management.endpoint.health.db.enabled', false);
        $config->set('firefly.observability.metrics.enabled', $this->metricsEnabled());
        $config->set('firefly.resilience.circuit-breaker.demo', ['failure-threshold' => 1]);
    }

    /**
     * The master gate under test — enabled here; a disabled sibling overrides this to false to prove the
     * property-gate takes MeterRegistry/CqrsMetrics/the endpoints down with it (§7 risk 1/4). Mirrors
     * ActuatorCapstoneTestCase::managementEnabled()'s template-method idiom: firefly.observability.metrics.enabled
     * is read by ObservabilityAutoConfiguration/the #[ConditionalOnProperty]-gated components at BOOT time, so this
     * MUST be a separate boot, not a post-boot config()->set().
     */
    protected function metricsEnabled(): bool
    {
        return true;
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app->instance(ScheduledManifest::class, new ScheduledManifest([]));
    }
}
