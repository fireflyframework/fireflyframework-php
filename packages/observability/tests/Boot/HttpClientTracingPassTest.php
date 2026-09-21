<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Config\Profile\Profiles;
use Firefly\Context\Boot\BootContext;
use Firefly\Context\Boot\BootPhase;
use Firefly\Context\Condition\ConditionEvaluationReport;
use Firefly\Context\Condition\ConditionEvaluator;
use Firefly\Context\Definition\BeanDefinitionRegistry;
use Firefly\Observability\Boot\HttpClientTracingPass;
use Firefly\Observability\Tracing\Tracer;
use Firefly\Testing\Double\RecordingTracer;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * Built the way MeterBindingsPassTest builds its BootContext: a bare container, a Config over the given keys,
 * an empty definition registry, no profiles, and a fresh report.
 *
 * @param  array<string, mixed>  $tracing
 */
function tracingBootContext(Container $container, array $tracing): BootContext
{
    $config = new Config(new ConfigRepository(['firefly' => ['observability' => ['tracing' => $tracing]]]));

    return new BootContext(
        container: $container,
        definitions: new BeanDefinitionRegistry,
        config: $config,
        profiles: new Profiles([]),
        conditions: new ConditionEvaluator($config, new Profiles([])),
        report: new ConditionEvaluationReport,
    );
}

it('installs the middleware on the Http factory when tracing and the http-client switch are on', function () {
    $container = new Container;
    $container->instance(Tracer::class, new RecordingTracer);
    $factory = new HttpFactory;
    $container->instance(HttpFactory::class, $factory);

    $pass = new HttpClientTracingPass;
    expect($pass->phase())->toBe(BootPhase::WiringPasses)->and($pass->order())->toBe(0);

    $pass->run(tracingBootContext($container, ['enabled' => true]));

    expect($factory->getGlobalMiddleware())->toHaveCount(1);
});

it('does nothing while tracing is off, or when only the http-client switch is off', function () {
    $container = new Container;
    $container->instance(Tracer::class, new RecordingTracer);
    $factory = new HttpFactory;
    $container->instance(HttpFactory::class, $factory);

    (new HttpClientTracingPass)->run(tracingBootContext($container, []));
    (new HttpClientTracingPass)->run(tracingBootContext($container, ['enabled' => true, 'http-client' => ['enabled' => false]]));

    expect($factory->getGlobalMiddleware())->toBe([]);
});
