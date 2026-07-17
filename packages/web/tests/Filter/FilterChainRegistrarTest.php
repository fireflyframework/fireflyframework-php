<?php

declare(strict_types=1);

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
use Firefly\Web\Filter\CorrelationIdFilter;
use Firefly\Web\Filter\FilterChainRegistrar;
use Firefly\Web\Filter\RequestContextFilter;
use Firefly\Web\Tests\Fixtures\Filters\FirstFilter;
use Firefly\Web\Tests\Fixtures\Filters\SecondFilter;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;

function filterDefinition(string $class, int $order): BeanDefinition
{
    return new BeanDefinition(new ComponentDescriptor(
        class: $class, stereotype: 'service', name: null, scope: Scope::Singleton,
        primary: false, order: $order, qualifier: null, interfaces: [], beans: [],
    ));
}

function bootContextWith(BeanDefinitionRegistry $registry): BootContext
{
    $config = new Config(new Repository([]));

    return new BootContext(
        container: new Container,
        definitions: $registry,
        config: $config,
        profiles: new Profiles([]),
        conditions: new ConditionEvaluator($config, new Profiles([])),
        report: new ConditionEvaluationReport,
    );
}

it('orders framework filters first, then WebFilter beans by #[Order] from the manifest', function () {
    $registry = new BeanDefinitionRegistry;
    // Register in the WRONG order to prove the sort uses #[Order], not insertion order.
    $registry->add(filterDefinition(SecondFilter::class, 20));
    $registry->add(filterDefinition(FirstFilter::class, 10));

    $ordered = (new FilterChainRegistrar)->orderedFilters(bootContextWith($registry));

    expect($ordered)->toBe([
        RequestContextFilter::class,
        CorrelationIdFilter::class,
        FirstFilter::class,
        SecondFilter::class,
    ]);
});

it('reports the registrar phase and order for the boot pipeline', function () {
    $registrar = new FilterChainRegistrar;

    expect($registrar->phase())->toBe(BootPhase::WiringPasses)
        ->and($registrar->order())->toBe(100);
});
