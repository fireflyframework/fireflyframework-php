<?php

declare(strict_types=1);

use Firefly\Actuator\Boot\HealthContributorRegistrar;
use Firefly\Actuator\Health\Health;
use Firefly\Actuator\Health\HealthContributorRegistry;
use Firefly\Actuator\Health\HealthIndicator;
use Firefly\Config\Config;
use Firefly\Config\Profile\Profiles;
use Firefly\Container\Descriptor\ComponentDescriptor;
use Firefly\Container\Scope;
use Firefly\Context\Boot\BootContext;
use Firefly\Context\Boot\BootPhase;
use Firefly\Context\Condition\ConditionEvaluationReport;
use Firefly\Context\Condition\ConditionEvaluator;
use Firefly\Context\Definition\BeanDefinition;
use Firefly\Context\Definition\BeanDefinitionRegistry;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;

final class FakePingHealthIndicator implements HealthIndicator
{
    public function health(): Health
    {
        return Health::up();
    }
}

it('discovers HealthIndicator beans and registers them under a derived name', function () {
    $container = new Container;
    $registry = new HealthContributorRegistry;
    $container->instance(HealthContributorRegistry::class, $registry);
    $container->instance(FakePingHealthIndicator::class, new FakePingHealthIndicator);

    $definitions = new BeanDefinitionRegistry;
    $definitions->add(new BeanDefinition(new ComponentDescriptor(
        class: FakePingHealthIndicator::class,
        stereotype: 'component',
        name: null,
        scope: Scope::Singleton,
        primary: false,
        order: 0,
        qualifier: null,
        interfaces: [HealthIndicator::class],
        beans: [],
    )));

    $config = new Config(new Repository([]));
    $context = new BootContext(
        container: $container,
        definitions: $definitions,
        config: $config,
        profiles: new Profiles([]),
        // NOTE: fixed from the brief's literal `new ConditionEvaluator(new Profiles([]))`, which does
        // not compile — ConditionEvaluator::__construct(Config $config, Profiles $profiles) requires
        // BOTH args (see packages/context/src/Condition/ConditionEvaluator.php). Matches the repo's
        // established idiom (packages/actuator/tests/Boot/ActuatorRouteRegistrarTest.php).
        conditions: new ConditionEvaluator($config, new Profiles([])),
        report: new ConditionEvaluationReport,
    );

    (new HealthContributorRegistrar)->run($context);

    expect(array_keys($registry->all()))->toBe(['fakeping']);
});

it('runs at WiringPasses order 10', function () {
    $pass = new HealthContributorRegistrar;
    expect($pass->phase())->toBe(BootPhase::WiringPasses)->and($pass->order())->toBe(10);
});
