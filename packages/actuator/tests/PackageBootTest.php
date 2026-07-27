<?php

declare(strict_types=1);

use Firefly\Actuator\ActuatorServiceProvider;
use Firefly\Actuator\ActuatorWiringProvider;
use Firefly\Context\Boot\ApplicationContext;
use Firefly\Scheduling\Schedule\ScheduledManifest;
use Firefly\Web\Route\RouteManifest;
use Illuminate\Foundation\Application;

/**
 * Binds empty RouteManifest/ScheduledManifest instances directly (via the harness's `bindings:` menu) rather than
 * registering the FULL WebServiceProvider/SchedulingServiceProvider/SchedulingWiringProvider stack —
 * the same "stub the cross-package seam, don't drag in the sibling's whole boot pipeline" idiom
 * CqrsWiringProvider's own bare-skeleton tests use for EventPublisher (see
 * packages/cqrs/tests/BareSkeletonBootTest.php / RealProviderBootTest.php's fakeEventPublisher()).
 * Registering SchedulingServiceProvider directly was tried and rejected: it is a REAL
 * AutoConfiguration whose compiled manifest describes actual #[Bean]s (e.g. DistributedLock), which
 * cascade into needing `cache.store` and more — infrastructure this "bare actuator skeleton" test has
 * no business standing up. Whether/how Web and Scheduling themselves bind a REAL, non-empty
 * RouteManifest/ScheduledManifest in production is already covered by ***their own*** boot tests
 * (packages/web/tests/PackageBootsTest.php, packages/scheduling/tests/PackageBootsTest.php); this
 * test's only job is: given SOME RouteManifest/ScheduledManifest exists in the container (however it
 * got there), does actuator's OWN component wiring (MappingsEndpoint/ScheduledTasksEndpoint, added in
 * T9) resolve without crashing eager resolution?
 */
function bootActuatorApp(): Application
{
    return fireflyApplication(
        config: ['firefly' => ['management' => ['enabled' => true]]],
        providers: [ActuatorServiceProvider::class, ActuatorWiringProvider::class],
        bindings: [
            RouteManifest::class => new RouteManifest([]),
            ScheduledManifest::class => new ScheduledManifest([]),
        ],
    );
}

it('boots a bare skeleton with the actuator providers registered', function () {
    $app = bootActuatorApp();

    expect($app->make(ApplicationContext::class))->toBeInstanceOf(ApplicationContext::class);
});
