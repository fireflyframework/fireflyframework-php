<?php

declare(strict_types=1);

use Firefly\Actuator\Boot\ActuatorRouteRegistrar;
use Firefly\Actuator\Endpoint\ActuatorRegistry;
use Firefly\Actuator\Endpoint\ExposureModel;
use Firefly\Actuator\Server\ManagementServerSettings;
use Firefly\Config\Config;
use Firefly\Config\Profile\Profiles;
use Firefly\Context\Boot\BootContext;
use Firefly\Context\Boot\BootPhase;
use Firefly\Context\Condition\ConditionEvaluationReport;
use Firefly\Context\Condition\ConditionEvaluator;
use Firefly\Context\Definition\BeanDefinitionRegistry;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Routing\Router;

/**
 * ManagementServerSettings is bound explicitly for the same reason ExposureModel is: both are #[Bean]s on
 * ActuatorAutoConfiguration in a real boot, and neither is autowirable from a bare Container (their constructors
 * take scalars/arrays). The registrar resolves them, so this harness has to supply them.
 *
 * @param  array<string, mixed>  $management
 * @param  array<string, mixed>  $firefly  extra top-level firefly.* config (e.g. `server.port`)
 */
function registrarContext(array $management, array $firefly = []): BootContext
{
    $container = new Container;
    $repository = new Repository(['firefly' => ['management' => $management] + $firefly]);
    $config = new Config($repository);
    $container->instance('config', $repository);
    $container->instance(Config::class, $config);
    $container->instance(ExposureModel::class, ExposureModel::fromConfig($config));
    $container->instance(ManagementServerSettings::class, ManagementServerSettings::fromConfig($config));
    $container->instance(ActuatorRegistry::class, new ActuatorRegistry);
    $container->instance('router', new Router(new Dispatcher($container), $container));
    $report = new ConditionEvaluationReport;

    return new BootContext(
        container: $container,
        definitions: new BeanDefinitionRegistry,
        config: $config,
        profiles: new Profiles([]),
        // NOTE: fixed from the brief's literal `new ConditionEvaluator(new Profiles([]))`, which does
        // not compile — ConditionEvaluator::__construct(Config $config, Profiles $profiles) requires
        // BOTH args (see packages/context/src/Condition/ConditionEvaluator.php). Matches the repo's
        // single established idiom (packages/web/tests/Dispatch/RouteWiringPassTest.php).
        conditions: new ConditionEvaluator($config, new Profiles([])),
        report: $report,
    );
}

it('registers the index + catch-all routes under the base path when management is enabled', function () {
    $context = registrarContext(['enabled' => true]);

    (new ActuatorRouteRegistrar)->run($context);

    /** @var Router $router */
    $router = $context->container->make('router');
    $uris = collect($router->getRoutes()->getRoutes())->map(fn ($r) => $r->uri())->all();

    expect($uris)->toContain('actuator')->toContain('actuator/{path}')
        ->and($context->container->bound(ConditionEvaluationReport::class))->toBeTrue();
});

it('registers NO routes when the master gate is off', function () {
    $context = registrarContext(['enabled' => false]);

    (new ActuatorRouteRegistrar)->run($context);

    /** @var Router $router */
    $router = $context->container->make('router');
    expect($router->getRoutes()->getRoutes())->toBeEmpty();
});

it('mounts under the management server base path when one is configured', function () {
    $context = registrarContext(['enabled' => true, 'server' => ['base-path' => '/manage']]);

    (new ActuatorRouteRegistrar)->run($context);

    /** @var Router $router */
    $router = $context->container->make('router');
    $uris = collect($router->getRoutes()->getRoutes())->map(fn ($r) => $r->uri())->all();

    expect($uris)->toContain('manage/actuator')->toContain('manage/actuator/{path}');
});

// A management port equal to the application port would leave ManagementPortGuard permitting every request — a
// config file that reads as isolated and is not. The registrar aborts the boot rather than mounting that.
it('aborts the boot when the management port is the application port', function () {
    $context = registrarContext(
        ['enabled' => true, 'server' => ['port' => 8000]],
        ['server' => ['port' => 8000]],
    );

    expect(fn () => (new ActuatorRouteRegistrar)->run($context))
        ->toThrow(ConfigurationException::class, 'is the application port');
});

it('mounts normally when the management port differs from the application port', function () {
    $context = registrarContext(
        ['enabled' => true, 'server' => ['port' => 9001]],
        ['server' => ['port' => 8000]],
    );

    (new ActuatorRouteRegistrar)->run($context);

    /** @var Router $router */
    $router = $context->container->make('router');
    expect(collect($router->getRoutes()->getRoutes())->map(fn ($r) => $r->uri())->all())->toContain('actuator');
});

// The master gate runs FIRST on purpose: an application with the actuator switched off has no management surface
// to isolate and must not be blocked from booting over the configuration of one.
it('does not validate the management port when the master gate is off', function () {
    $context = registrarContext(
        ['enabled' => false, 'server' => ['port' => 8000]],
        ['server' => ['port' => 8000]],
    );

    (new ActuatorRouteRegistrar)->run($context);

    /** @var Router $router */
    $router = $context->container->make('router');
    expect($router->getRoutes()->getRoutes())->toBeEmpty();
});

it('is a WiringPasses pass ordered 50', function () {
    $pass = new ActuatorRouteRegistrar;
    expect($pass->phase())->toBe(BootPhase::WiringPasses)->and($pass->order())->toBe(50);
});
