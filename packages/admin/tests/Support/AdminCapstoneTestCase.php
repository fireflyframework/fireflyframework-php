<?php

declare(strict_types=1);

namespace Firefly\Admin\Tests\Support;

use Firefly\Actuator\ActuatorServiceProvider;
use Firefly\Actuator\ActuatorWiringProvider;
use Firefly\Admin\AdminServiceProvider;
use Firefly\Testing\Boot\FireflyBoot;
use Firefly\Testing\FireflyDatabaseTestCase;
use Firefly\Validation\ValidationServiceProvider;
use Firefly\Web\WebServiceProvider;
use Illuminate\Foundation\Application;

/**
 * Boots the dashboard over testbench with a real Web layer and a real actuator, so /firefly/* dispatches
 * through the HTTP kernel and renders against endpoints that were actually registered at boot.
 *
 * Exposure is left at its secure default (health,info) ON PURPOSE: the dashboard is supposed to render
 * beans, conditions, mappings and env without any of them being published over HTTP, and a test that
 * widened exposure would not prove that.
 */
abstract class AdminCapstoneTestCase extends FireflyDatabaseTestCase
{
    protected function fireflyProviders(): array
    {
        return [
            ValidationServiceProvider::class,
            WebServiceProvider::class,
            ActuatorServiceProvider::class,
            ActuatorWiringProvider::class,
            AdminServiceProvider::class,
        ];
    }

    /** @return array<string, mixed> */
    protected function configOverrides(): array
    {
        return [
            'firefly.management.enabled' => true,
            'firefly.management.endpoint.health.db.enabled' => true,
            'firefly.admin.enabled' => $this->adminEnabled(),
        ];
    }

    protected function adminEnabled(): bool
    {
        return true;
    }

    protected function defineFireflyEnvironment(Application $app): void
    {
        // ScheduledTasksEndpoint is eagerly resolved and needs a bound manifest; no Scheduling provider is
        // registered here, so use the shared harness stub (same reasoning as ActuatorCapstoneTestCase).
        FireflyBoot::stubScheduledManifest($app);
    }
}
