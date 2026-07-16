<?php

declare(strict_types=1);

use Firefly\AutoConfigure\AutoConfigurationCollector;
use Firefly\AutoConfigure\Pass\AutoConfigurationsPass;
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
use Firefly\Context\Definition\DefinitionSource;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;

function drainDefinition(string $class, DefinitionSource $source): BeanDefinition
{
    return new BeanDefinition(
        new ComponentDescriptor(
            class: $class, stereotype: 'configuration', name: null, scope: Scope::Singleton,
            primary: false, order: 0, qualifier: null, interfaces: [], beans: [],
        ),
        [], $source, [],
    );
}

function drainBootContext(BeanDefinitionRegistry $registry): BootContext
{
    $config = new Config(new Repository([]));
    $profiles = new Profiles([]);

    return new BootContext(
        container: new Container, definitions: $registry, config: $config, profiles: $profiles,
        conditions: new ConditionEvaluator($config, $profiles), report: new ConditionEvaluationReport,
    );
}

it('reports phase 500 and order 0', function () {
    $pass = new AutoConfigurationsPass(new AutoConfigurationCollector);
    expect($pass->phase())->toBe(BootPhase::AutoConfigurations)
        ->and($pass->phase()->value)->toBe(500)
        ->and($pass->order())->toBe(0);
});

it('drains the collector into the registry, forcing DefinitionSource::AutoConfiguration', function () {
    $collector = new AutoConfigurationCollector;
    // Even if a def arrives tagged User, this pass must re-force AutoConfiguration (mirrors UserConfigurationsPass).
    $collector->setAssembledDefinitions([
        drainDefinition('App\\CacheAutoConfiguration', DefinitionSource::User),
        drainDefinition('App\\MailAutoConfiguration', DefinitionSource::AutoConfiguration),
    ]);

    $registry = new BeanDefinitionRegistry;
    (new AutoConfigurationsPass($collector))->run(drainBootContext($registry));

    $all = $registry->all();
    expect($all)->toHaveCount(2)
        ->and($all[0]->source)->toBe(DefinitionSource::AutoConfiguration)
        ->and($all[1]->source)->toBe(DefinitionSource::AutoConfiguration)
        ->and(array_map(fn ($d) => $d->class(), $all))
        ->toBe(['App\\CacheAutoConfiguration', 'App\\MailAutoConfiguration']);
});
