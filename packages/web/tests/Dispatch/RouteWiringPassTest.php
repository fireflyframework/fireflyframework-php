<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Config\Profile\Profiles;
use Firefly\Context\Boot\BootContext;
use Firefly\Context\Condition\ConditionEvaluationReport;
use Firefly\Context\Condition\ConditionEvaluator;
use Firefly\Context\Definition\BeanDefinitionRegistry;
use Firefly\Validation\Constraint\BeanValidator;
use Firefly\Validation\Constraint\ConstraintManifest;
use Firefly\Validation\IlluminateValidator;
use Firefly\Web\Dispatch\ArgumentResolver;
use Firefly\Web\Dispatch\ControllerDispatcher;
use Firefly\Web\Dispatch\ResponseFactory;
use Firefly\Web\Dispatch\RouteWiringPass;
use Firefly\Web\Exception\ExceptionHandlerRegistry;
use Firefly\Web\Http\JsonMessageConverter;
use Firefly\Web\Http\MessageConverterRegistry;
use Firefly\Web\Route\RouteManifest;
use Firefly\Web\Route\RouteScanner;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher as EventsDispatcher;
use Illuminate\Http\Request;
use Illuminate\Routing\CallableDispatcher;
use Illuminate\Routing\Contracts\CallableDispatcher as CallableDispatcherContract;
use Illuminate\Routing\Router;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory as IlluminateFactory;

it('registers a matchable native route whose closure binds args and negotiates the return', function () {
    $container = new Container;
    $router = new Router(new EventsDispatcher($container), $container);
    $container->instance('router', $router);
    // Closure-action routes dispatch through Laravel's CallableDispatcher; a bare container (no
    // RoutingServiceProvider) must bind it exactly as Illuminate's RoutingServiceProvider does.
    $container->singleton(CallableDispatcherContract::class, static fn (Container $app): CallableDispatcher => new CallableDispatcher($app));

    $converters = new MessageConverterRegistry([new JsonMessageConverter]);
    $beanValidator = new BeanValidator(
        new IlluminateValidator(new IlluminateFactory(new Translator(new ArrayLoader, 'en'))),
        new ConstraintManifest([]),
    );
    $dispatcher = new ControllerDispatcher(
        $container,
        new ArgumentResolver($converters, $beanValidator),
        new ResponseFactory($converters),
        new ExceptionHandlerRegistry([]),
    );

    $manifest = new RouteManifest((new RouteScanner)->scan(['Firefly\\Web\\Tests\\Fixtures\\' => dirname(__DIR__).'/Fixtures']));
    $container->instance(RouteManifest::class, $manifest);
    $container->instance(ControllerDispatcher::class, $dispatcher);

    $config = new Config(new Repository([]));
    $context = new BootContext(
        container: $container,
        definitions: new BeanDefinitionRegistry,
        config: $config,
        profiles: new Profiles([]),
        conditions: new ConditionEvaluator($config, new Profiles([])),
        report: new ConditionEvaluationReport,
    );

    (new RouteWiringPass)->run($context);

    $request = Request::create('/accounts/7?view=full', 'GET');
    // The closure type-hints Request; Laravel's CallableDispatcher resolves that from the container, so the
    // dispatched request must be bound as the HTTP kernel would (else it news up a blank Request with no route).
    $container->instance(Request::class, $request);
    $response = $router->dispatch($request);

    expect($response->getStatusCode())->toBe(200)
        ->and(json_decode((string) $response->getContent(), true))->toBe(['id' => 7, 'view' => 'full', 'trace' => null]);
});
