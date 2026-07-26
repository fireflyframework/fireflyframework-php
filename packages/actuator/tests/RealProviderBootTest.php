<?php

declare(strict_types=1);

use Firefly\Actuator\ActuatorServiceProvider;
use Firefly\Actuator\ActuatorWiringProvider;
use Firefly\Actuator\Endpoint\ActuatorRegistry;
use Firefly\Actuator\Endpoint\ExposureModel;
use Firefly\AutoConfigure\FireflyAutoConfigureServiceProvider;
use Firefly\Scheduling\Schedule\ScheduledManifest;
use Firefly\Validation\ValidationServiceProvider;
use Firefly\Web\WebServiceProvider;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\Kernel as FoundationHttpKernel;
use Illuminate\Routing\Router;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory as IlluminateFactory;

/**
 * NOTE (brief-test fix, three gaps): (1) the brief's draft omitted binding ScheduledManifest.
 * ScheduledTasksEndpoint (#[ConditionalOnClass(ScheduledManifest::class)]) survives condition filtering in THIS
 * monorepo because firefly/scheduling is a hard composer dependency of firefly/actuator (the class always
 * autoloads), so it is eagerly resolved at BootPhase::EagerSingletons (900) — its own docblock documents that
 * this requires ScheduledManifest to already be bound by then. Neither Scheduling provider is registered here
 * (dragging in the whole Scheduling boot pipeline is deliberately out of scope for an actuator-focused boot test
 * — the same "stub the cross-package seam" idiom PackageBootTest.php already established), so bind an empty
 * manifest directly, exactly as PackageBootTest.php does.
 * (2) the brief's draft also omitted binding Illuminate\Contracts\Validation\Factory. WebServiceProvider's own
 * BeanValidator bean resolves Firefly\Validation\Validator, whose ValidationAutoConfiguration default in turn
 * needs the illuminate Factory — a real host app supplies it via Illuminate\Validation\ValidationServiceProvider,
 * which a bare Application never registers. Bind a real Factory directly, exactly as
 * packages/validation/tests/ShippedProviderBootTest.php's own real-provider boot already does.
 * (3) the brief's draft also omitted binding Illuminate\Contracts\Http\Kernel. WebServiceProvider's
 * FilterChainRegistrar BootPass resolves it to read the app's HTTP middleware; a bare Application never binds
 * it (bootstrap/app.php normally does). Bind a real Foundation Kernel directly, exactly as
 * packages/web/tests/PackageBootsTest.php's own real-provider boot already does.
 *
 * @param  array<string, mixed>  $management  the `firefly.management.*` tree for this boot
 */
function bootRealActuator(array $management = ['enabled' => true]): Application
{
    $app = new Application;
    $app->instance('config', new Repository(['firefly' => ['management' => $management], 'logging' => ['channels' => []]]));
    $app->instance(ScheduledManifest::class, new ScheduledManifest([]));
    $app->instance(Factory::class, new IlluminateFactory(new Translator(new ArrayLoader, 'en')));
    $app->instance(HttpKernelContract::class, new FoundationHttpKernel($app, $app->make(Router::class)));
    $app->register(new FireflyAutoConfigureServiceProvider($app));
    $app->register(new ValidationServiceProvider($app));
    $app->register(new WebServiceProvider($app));
    $app->register(new ActuatorServiceProvider($app));
    $app->register(new ActuatorWiringProvider($app));
    $app->boot();

    return $app;
}

it('binds the ExposureModel from ActuatorAutoConfiguration', function () {
    expect(bootRealActuator()->make(ExposureModel::class))->toBeInstanceOf(ExposureModel::class);
});

it('populates the ActuatorRegistry with the built-in endpoints', function () {
    $registry = bootRealActuator()->make(ActuatorRegistry::class);

    expect($registry->ids())->toContain('health')->toContain('info')->toContain('env')
        ->toContain('beans')->toContain('conditions')->toContain('mappings')->toContain('loggers');
});
