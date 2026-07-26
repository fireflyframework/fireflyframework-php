<?php

declare(strict_types=1);

use Firefly\Actuator\Boot\ActuatorRouteRegistrar;
use Firefly\Actuator\Endpoint\ActuatorRegistry;
use Firefly\Actuator\Endpoint\ExposureModel;
use Firefly\Config\Config;
use Firefly\Config\Profile\Profiles;
use Firefly\Context\Boot\BootContext;
use Firefly\Context\Boot\BootPhase;
use Firefly\Context\Condition\ConditionEvaluationReport;
use Firefly\Context\Condition\ConditionEvaluator;
use Firefly\Context\Definition\BeanDefinitionRegistry;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Routing\Router;

/**
 * @param  array<string, mixed>  $management
 */
function registrarContext(array $management): BootContext
{
    $container = new Container;
    $repository = new Repository(['firefly' => ['management' => $management]]);
    $config = new Config($repository);
    $container->instance('config', $repository);
    $container->instance(Config::class, $config);
    $container->instance(ExposureModel::class, ExposureModel::fromConfig($config));
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

it('is a WiringPasses pass ordered 50', function () {
    $pass = new ActuatorRouteRegistrar;
    expect($pass->phase())->toBe(BootPhase::WiringPasses)->and($pass->order())->toBe(50);
});
