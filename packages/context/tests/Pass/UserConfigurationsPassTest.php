<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Config\Profile\Profiles;
use Firefly\Container\Descriptor\ComponentDescriptor;
use Firefly\Container\Scope;
use Firefly\Context\Boot\BootContext;
use Firefly\Context\Condition\ConditionEvaluationReport;
use Firefly\Context\Condition\ConditionEvaluator;
use Firefly\Context\Definition\BeanDefinition;
use Firefly\Context\Definition\BeanDefinitionRegistry;
use Firefly\Context\Definition\DefinitionSource;
use Firefly\Context\Pass\UserConfigurationsPass;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;

function userPassDescriptor(string $class): ComponentDescriptor
{
    return new ComponentDescriptor(
        class: $class,
        stereotype: 'Configuration',
        name: null,
        scope: Scope::Singleton,
        primary: false,
        order: 0,
        qualifier: null,
        interfaces: [],
        beans: [],
    );
}

function userPassContext(): BootContext
{
    $config = new Config(new Repository([]));
    $profiles = new Profiles([]);

    return new BootContext(
        container: new Container,
        definitions: new BeanDefinitionRegistry,
        config: $config,
        profiles: $profiles,
        conditions: new ConditionEvaluator($config, $profiles),
        report: new ConditionEvaluationReport,
    );
}

it('adds nothing when no definitions are supplied (M4 has no context scanner yet)', function () {
    $context = userPassContext();

    (new UserConfigurationsPass)->run($context);

    expect($context->definitions->all())->toBe([]);
});

it('adds every supplied definition to the registry, tagged DefinitionSource::User', function () {
    $context = userPassContext();
    $descriptorA = userPassDescriptor('App\ConfigA');
    $descriptorB = userPassDescriptor('App\ConfigB');

    $pass = new UserConfigurationsPass([
        new BeanDefinition($descriptorA),
        // Deliberately constructed with a non-User source to prove the pass FORCES User,
        // rather than trusting whatever the caller happened to pass in.
        new BeanDefinition($descriptorB, source: DefinitionSource::AutoConfiguration),
    ]);

    $pass->run($context);

    $added = $context->definitions->all();
    expect($added)->toHaveCount(2)
        ->and($added[0]->descriptor)->toBe($descriptorA)
        ->and($added[0]->source)->toBe(DefinitionSource::User)
        ->and($added[1]->descriptor)->toBe($descriptorB)
        ->and($added[1]->source)->toBe(DefinitionSource::User);
});
