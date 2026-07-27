<?php

declare(strict_types=1);

namespace Firefly\Observability\Tests\Support;

use Firefly\Actuator\ActuatorServiceProvider;
use Firefly\Actuator\ActuatorWiringProvider;
use Firefly\Cqrs\CqrsServiceProvider;
use Firefly\Cqrs\CqrsWiringProvider;
use Firefly\Observability\ObservabilityServiceProvider;
use Firefly\Observability\ObservabilityWiringProvider;
use Firefly\Resilience\ResilienceServiceProvider;
use Firefly\Testing\Boot\FireflyBoot;
use Firefly\Testing\FireflyTestCase;
use Firefly\Validation\ValidationServiceProvider;
use Firefly\Web\WebServiceProvider;
use Illuminate\Foundation\Application;

/**
 * Boots actuator + observability over a real Web layer so /actuator/prometheus scrapes through the HTTP kernel.
 *
 * NOTE (brief-test fix, two gaps beyond the brief's literal draft):
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
 * The ScheduledManifest stub (the SAME brief-test gap `ActuatorCapstoneTestCase`/`RealProviderBootTest` already
 * documented and fixed: `ScheduledTasksEndpoint` (`#[ConditionalOnClass(ScheduledManifest::class)]`) survives
 * condition filtering because `firefly/scheduling` is a hard composer dependency of `firefly/actuator`) and the
 * "no protected cross-scope $this->app access from a Pest it() closure" PHPStan gap are both absorbed by the
 * FireflyTestCase harness now: FireflyBoot::stubScheduledManifest() below, and the harness's own public app()
 * accessor in place of this class's former bespoke capstoneApp().
 */
abstract class ObservabilityCapstoneTestCase extends FireflyTestCase
{
    protected function fireflyProviders(): array
    {
        return [
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

    protected function configOverrides(): array
    {
        return [
            'cache.default' => 'array',
            'firefly.management.enabled' => true,
            'firefly.management.endpoints.web.exposure.include' => 'health,info,prometheus,metrics',
            'firefly.management.endpoint.health.db.enabled' => false,
            'firefly.observability.metrics.enabled' => $this->metricsEnabled(),
            'firefly.resilience.circuit-breaker.demo' => ['failure-threshold' => 1],
        ];
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

    protected function defineFireflyEnvironment(Application $app): void
    {
        FireflyBoot::stubScheduledManifest($app);
    }
}
