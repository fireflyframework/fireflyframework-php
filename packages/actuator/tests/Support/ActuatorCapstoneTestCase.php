<?php

declare(strict_types=1);

namespace Firefly\Actuator\Tests\Support;

use Firefly\Actuator\ActuatorServiceProvider;
use Firefly\Actuator\ActuatorWiringProvider;
use Firefly\AutoConfigure\FireflyAutoConfigureServiceProvider;
use Firefly\Scheduling\Schedule\ScheduledManifest;
use Firefly\Validation\ValidationServiceProvider;
use Firefly\Web\WebServiceProvider;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase;

/**
 * Boots the actuator over testbench + a real Web layer so /actuator/* routes dispatch through the HTTP kernel. A
 * sqlite :memory: connection is configured AND the opt-in DB indicator is explicitly enabled so DbHealthIndicator
 * reports UP (→ /health aggregates UP → 200).
 */
abstract class ActuatorCapstoneTestCase extends TestCase
{
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
        $config->set('database.default', 'testing');
        $config->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $config->set('firefly.management.enabled', $this->managementEnabled());
        $config->set('firefly.management.endpoint.health.db.enabled', true);
        $config->set('firefly.management.endpoints.web.exposure.include', $this->exposureInclude());
    }

    /**
     * The master gate under test — enabled here; ActuatorDisabledCapstoneTestCase overrides this to false to
     * prove NO route is mounted (firefly.management.enabled is read by ActuatorRouteRegistrar at BOOT time, so
     * this MUST be a separate boot, not a post-boot config()->set() — the same edaProvider()/messagingProvider()
     * template-method idiom EdaQueueCapstoneTestCase/MessagingQueueCapstoneTestCase already use).
     */
    protected function managementEnabled(): bool
    {
        return true;
    }

    /**
     * The secure-default exposure list under test — ActuatorEnvExposedCapstoneTestCase overrides this to add
     * `env` to prove a sensitive endpoint becomes reachable once explicitly exposed. Same "must be a separate
     * boot" reasoning as managementEnabled(): ExposureModel is a singleton #[Bean] whose include/exclude arrays
     * are captured ONCE at construction (BootPhase::FlushDefinitions, 650) — mutating
     * firefly.management.endpoints.web.exposure.include via a post-boot config()->set() inside a test body never
     * reaches the already-resolved instance (readonly arrays, no live re-read — unlike the PER-ENDPOINT
     * firefly.management.endpoint.{id}.enabled flag, which ActuatorDispatchAction/ActuatorIndexAction DO read
     * live off Config at request time; ExposureModel's own docblock documents this exact split).
     */
    protected function exposureInclude(): string
    {
        return 'health,info';
    }

    /**
     * NOTE (brief-test fix): the brief's draft omitted this binding. ScheduledTasksEndpoint
     * (#[ConditionalOnClass(ScheduledManifest::class)]) survives condition filtering here — firefly/scheduling is
     * a hard composer dependency of firefly/actuator, so the class always autoloads — and is eagerly resolved at
     * BootPhase::EagerSingletons (900); its own docblock documents that ScheduledManifest must already be bound
     * by then. No Scheduling provider is registered above (out of scope for an actuator HTTP capstone), so bind
     * an empty manifest directly here, in defineEnvironment() — testbench runs this AFTER provider register()
     * but BEFORE $app->boot(), the same seam WebCapstoneTestCase uses for its own manifest overrides.
     *
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app->instance(ScheduledManifest::class, new ScheduledManifest([]));
    }
}
