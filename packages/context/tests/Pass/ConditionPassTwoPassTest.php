<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Config\Profile\Profiles;
use Firefly\Container\Descriptor\ComponentDescriptor;
use Firefly\Container\Scope;
use Firefly\Context\Boot\BootContext;
use Firefly\Context\Condition\Attributes\ConditionalOnMissingBean;
use Firefly\Context\Condition\ConditionEvaluationReport;
use Firefly\Context\Condition\ConditionEvaluator;
use Firefly\Context\Definition\BeanDefinition;
use Firefly\Context\Definition\BeanDefinitionRegistry;
use Firefly\Context\Definition\DefinitionSource;
use Firefly\Context\Pass\ConditionPassTwoPass;
use Firefly\Context\Tests\Fixtures\Cache;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;

/**
 * @param  list<class-string>  $interfaces
 */
function passTwoDescriptor(string $class, array $interfaces = []): ComponentDescriptor
{
    return new ComponentDescriptor(
        class: $class,
        stereotype: 'Service',
        name: null,
        scope: Scope::Singleton,
        primary: false,
        order: 0,
        qualifier: null,
        interfaces: $interfaces,
        beans: [],
    );
}

function passTwoContext(): BootContext
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

/**
 * @param  list<BeanDefinition>  $definitionsInOrder
 * @return list<string>
 */
function runPassTwoAndCollectRemaining(array $definitionsInOrder): array
{
    $context = passTwoContext();
    foreach ($definitionsInOrder as $definition) {
        $context->definitions->add($definition);
    }

    (new ConditionPassTwoPass)->run($context);

    return array_map(fn (BeanDefinition $d): string => $d->class(), $context->definitions->all());
}

it('removes a definition whose #[ConditionalOnMissingBean] type IS present in the registry', function () {
    $existing = new BeanDefinition(
        passTwoDescriptor('App\Existing', interfaces: [Cache::class]),
        source: DefinitionSource::AutoConfiguration,
    );
    $dependent = new BeanDefinition(
        passTwoDescriptor('App\Dependent'),
        conditions: [new ConditionalOnMissingBean(Cache::class)],
        source: DefinitionSource::AutoConfiguration,
    );

    $context = passTwoContext();
    $context->definitions->add($existing);
    $context->definitions->add($dependent);

    (new ConditionPassTwoPass)->run($context);

    $remaining = array_map(fn (BeanDefinition $d): string => $d->class(), $context->definitions->all());
    expect($remaining)->toBe(['App\Existing']);

    $entries = $context->report->all();
    expect($entries)->toHaveCount(1)
        ->and($entries[0]['class'])->toBe('App\Dependent')
        ->and($entries[0]['attribute'])->toBe(ConditionalOnMissingBean::class)
        ->and($entries[0]['outcome']->matched)->toBeFalse();
});

it('keeps a definition whose #[ConditionalOnMissingBean] type is absent from the registry', function () {
    $dependent = new BeanDefinition(
        passTwoDescriptor('App\DependentKept'),
        conditions: [new ConditionalOnMissingBean(Cache::class)],
        source: DefinitionSource::AutoConfiguration,
    );

    $context = passTwoContext();
    $context->definitions->add($dependent);

    (new ConditionPassTwoPass)->run($context);

    expect($context->definitions->all())->toHaveCount(1)
        ->and($context->report->all()[0]['outcome']->matched)->toBeTrue();
});

it('is snapshot-stable: two definitions with mutually-referencing bean conditions produce the SAME outcome regardless of registry insertion order', function () {
    // A is missing-bean on B's type; B is missing-bean on A's type. Evaluated against a LIVE,
    // being-mutated registry this would be order-dependent (whichever is evaluated first would
    // see the other still present and get removed; the second would then see the first already
    // gone and survive). Evaluated against a snapshot frozen at pass entry, BOTH see the other as
    // present and BOTH are removed — deterministically, regardless of iteration order.
    $classA = 'Firefly\Context\Tests\Pass\MutualA';
    $classB = 'Firefly\Context\Tests\Pass\MutualB';

    $definitionA = new BeanDefinition(
        passTwoDescriptor($classA),
        conditions: [new ConditionalOnMissingBean($classB)],
        source: DefinitionSource::AutoConfiguration,
    );
    $definitionB = new BeanDefinition(
        passTwoDescriptor($classB),
        conditions: [new ConditionalOnMissingBean($classA)],
        source: DefinitionSource::AutoConfiguration,
    );

    $remainingAB = runPassTwoAndCollectRemaining([$definitionA, $definitionB]);
    $remainingBA = runPassTwoAndCollectRemaining([$definitionB, $definitionA]);

    expect($remainingAB)->toBe([])
        ->and($remainingBA)->toBe([]);
});

it('propagates ConfigurationException, unsoftened, for a bean condition on a DefinitionSource::User definition', function () {
    $context = passTwoContext();
    $context->definitions->add(new BeanDefinition(
        passTwoDescriptor('App\UserThing'),
        conditions: [new ConditionalOnMissingBean(Cache::class)],
        source: DefinitionSource::User,
    ));

    (new ConditionPassTwoPass)->run($context);
})->throws(ConfigurationException::class);
