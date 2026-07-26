<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Config\Profile\Profiles;
use Firefly\Context\Boot\BootContext;
use Firefly\Context\Boot\BootPhase;
use Firefly\Context\Condition\ConditionEvaluationReport;
use Firefly\Context\Condition\ConditionEvaluator;
use Firefly\Context\Definition\BeanDefinitionRegistry;
use Firefly\Observability\Boot\MeterBindingsPass;
use Firefly\Observability\Metrics\MeterRegistry;
use Firefly\Observability\Metrics\SimpleMeterRegistry;
use Firefly\Resilience\ResilienceRegistry;
use Firefly\Resilience\Store\InMemoryResilienceStore;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;

// NOTE (brief-test bugs, fixed here — no production code change):
// 1. The brief's draft anonymous ResilienceStore stub implemented only get()/put()/withLock(), but the real
//    `Firefly\Resilience\Store\ResilienceStore` interface also declares add()/increment()/decrement()/forget() — an
//    anonymous class implementing the interface without those four methods is a fatal "contains 4 abstract methods"
//    error, never even reaching the assertions. The brief's own implementer note allows reusing an existing
//    in-memory fixture instead; `Firefly\Resilience\Store\InMemoryResilienceStore`
//    (packages/resilience/src/Store/InMemoryResilienceStore.php) is the shipped single-process implementation
//    already used by packages/resilience's own tests (CircuitBreakerTest, ResilienceRegistryTest), so it is used
//    here verbatim rather than hand-rolling a second one.
// 2. The brief's draft constructed `new ConditionEvaluator(new Profiles([]))` — but the real
//    `Firefly\Context\Condition\ConditionEvaluator::__construct(Config $config, Profiles $profiles)` takes TWO
//    arguments, `Config` first. Passing a lone `Profiles` throws a `TypeError` at construction (argument #1 must
//    be `Config`, `Profiles` given) before any assertion runs. Fixed by passing the already-built `$config` too.
/** @param array<string, mixed> $resilience */
function meterBindingsContext(array $resilience): BootContext
{
    $container = new Container;
    $repository = new Repository(['firefly' => ['resilience' => $resilience]]);
    $config = new Config($repository);
    $registry = new SimpleMeterRegistry;
    $container->instance(MeterRegistry::class, $registry);

    $store = new InMemoryResilienceStore;
    $container->instance(ResilienceRegistry::class, new ResilienceRegistry($resilience, $store));

    return new BootContext(
        container: $container,
        definitions: new BeanDefinitionRegistry,
        config: $config,
        profiles: new Profiles([]),
        conditions: new ConditionEvaluator($config, new Profiles([])),
        report: new ConditionEvaluationReport,
    );
}

it('registers process + circuit-breaker gauges when a MeterRegistry is bound', function () {
    $context = meterBindingsContext(['circuit-breaker' => ['payments' => ['failure-threshold' => 3]]]);

    (new MeterBindingsPass)->run($context);

    /** @var SimpleMeterRegistry $registry */
    $registry = $context->container->make(MeterRegistry::class);
    $names = array_map(fn ($m) => $m->name(), $registry->meters());

    expect($names)->toContain('process_resident_memory_bytes')->toContain('resilience_circuit_breaker_state');
});

it('runs at WiringPasses order 0', function () {
    $pass = new MeterBindingsPass;
    expect($pass->phase())->toBe(BootPhase::WiringPasses)->and($pass->order())->toBe(0);
});
