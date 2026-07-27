<?php

declare(strict_types=1);

namespace Firefly\Cli\Tests\Command;

use Firefly\Actuator\ActuatorServiceProvider;
use Firefly\Actuator\ActuatorWiringProvider;
use Firefly\Cli\CliServiceProvider;
use Firefly\Testing\Boot\FireflyBoot;
use Firefly\Testing\FireflyTestCase;
use Firefly\Validation\ValidationServiceProvider;
use Firefly\Web\WebServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Named Support test-case for IntrospectionCommandsTest — Pest's `uses()` requires a class-string
 * (`function uses(string ...$classAndTraits)`), and an anonymous `new class extends FireflyTestCase {
 * ... }::class` expression instantiates the class immediately (to read its ::class), which throws
 * before Pest ever gets to bind it: the ultimate ancestor PHPUnit\Framework\TestCase::__construct()
 * requires a `string $name` argument that a bare `new` expression never supplies (verified: this
 * exact fix is already applied in ClearCommandTestCase for the same reason).
 *
 * CliServiceProvider IS listed here (the brief's own snippet omits it) — without it, firefly:about/
 * :health/:routes/:metrics are never registered as Artisan commands and `$this->artisan(...)` throws
 * CommandNotFoundException. WebServiceProvider + ActuatorServiceProvider + ActuatorWiringProvider are
 * also listed so ActuatorRouteRegistrar populates the ActuatorRegistry with real endpoints at boot.
 *
 * ValidationServiceProvider is a 4th addition beyond the brief's own list (discovered empirically,
 * same category of fix as the CliServiceProvider omission): WebServiceProvider::registerBindings()
 * builds BeanValidator via `$app->make(Validator::class)`, and without ValidationServiceProvider
 * bound, that resolution throws `Target [Firefly\Validation\Validator] is not instantiable` during
 * boot — verified directly by running this test before adding it. ActuatorCapstoneTestCase (the
 * sibling HTTP-actuator capstone) lists the same provider for the same reason.
 *
 * Not `final`: Pest's uses() generates a per-test-file class that EXTENDS this one.
 */
class IntrospectionCommandsTestCase extends FireflyTestCase
{
    /** @return list<class-string<ServiceProvider>> */
    protected function fireflyProviders(): array
    {
        return [
            CliServiceProvider::class,
            ValidationServiceProvider::class,
            WebServiceProvider::class,
            ActuatorServiceProvider::class,
            ActuatorWiringProvider::class,
        ];
    }

    /** @return array<string, mixed> */
    protected function configOverrides(): array
    {
        return ['firefly.management.endpoints.web.exposure.include' => '*'];
    }

    /**
     * ScheduledTasksEndpoint (#[ConditionalOnClass(ScheduledManifest::class)]) survives condition
     * filtering here — firefly/scheduling is a hard composer dependency of firefly/actuator, so the
     * class always autoloads — and is eagerly resolved at BootPhase::EagerSingletons (900); no
     * Scheduling provider is registered above (out of scope for a CLI introspection test), so stub an
     * empty manifest via the shared harness helper — the exact idiom ActuatorCapstoneTestCase uses.
     */
    protected function defineFireflyEnvironment(Application $app): void
    {
        FireflyBoot::stubScheduledManifest($app);
    }
}
