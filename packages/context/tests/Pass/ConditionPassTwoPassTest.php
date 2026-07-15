<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Config\Profile\Profiles;
use Firefly\Container\Descriptor\ComponentDescriptor;
use Firefly\Container\Scope;
use Firefly\Context\Boot\BootContext;
use Firefly\Context\Condition\Attributes\ConditionalOnMissingBean;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Firefly\Context\Condition\ConditionEvaluationReport;
use Firefly\Context\Condition\ConditionEvaluator;
use Firefly\Context\Definition\BeanDefinition;
use Firefly\Context\Definition\BeanDefinitionRegistry;
use Firefly\Context\Definition\DefinitionSource;
use Firefly\Context\Pass\ConditionPassTwoPass;
use Firefly\Context\Tests\Fixtures\Cache;
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

/**
 * @param  array<string, mixed>  $configItems
 */
function passTwoContext(array $configItems = []): BootContext
{
    $config = new Config(new Repository($configItems));
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

it('does NOT touch a DefinitionSource::User definition — not this pass\'s job, ConditionPassOnePass already decided it', function () {
    // A User definition with a bean condition is a structural error the pipeline can only ever
    // observe at ConditionPassOnePass (see ConditionEvaluator's user-component rule): by the time
    // ConditionPassTwoPass runs, such a definition could never legitimately still be here. This
    // test proves the pass does not even attempt to evaluate it — it is filtered out by
    // DefinitionSource, not merely tolerated.
    $context = passTwoContext();
    $definition = new BeanDefinition(
        passTwoDescriptor('App\UserThing'),
        conditions: [new ConditionalOnMissingBean(Cache::class)],
        source: DefinitionSource::User,
    );
    $context->definitions->add($definition);

    (new ConditionPassTwoPass)->run($context);

    expect($context->definitions->all())->toBe([$definition])
        ->and($context->report->all())->toBe([]);
});

it('evaluates an AutoConfiguration definition\'s REGISTRY-INDEPENDENT condition — the latent bug this pass\'s reorder fixes', function () {
    // Before the M4 reorder, AutoConfigurations (600) ran AFTER ConditionPassTwo (500): an
    // auto-configuration's non-bean conditions never existed in the registry when any condition
    // pass ran, so they were never evaluated and always silently survived. ConditionPassTwoPass
    // now runs strictly after AutoConfigurations (BootPhase 500 < 600) and evaluates BOTH kinds of
    // condition for every AutoConfiguration definition — this proves the non-bean half.
    $context = passTwoContext(['firefly' => ['autoconfig' => ['on' => false]]]);
    $definition = new BeanDefinition(
        passTwoDescriptor('App\AutoThing'),
        conditions: [new ConditionalOnProperty('firefly.autoconfig.on', havingValue: 'true')],
        source: DefinitionSource::AutoConfiguration,
    );
    $context->definitions->add($definition);

    (new ConditionPassTwoPass)->run($context);

    expect($context->definitions->all())->toBe([]);

    $entries = $context->report->all();
    expect($entries)->toHaveCount(1)
        ->and($entries[0]['class'])->toBe('App\AutoThing')
        ->and($entries[0]['attribute'])->toBe(ConditionalOnProperty::class)
        ->and($entries[0]['outcome']->matched)->toBeFalse();
});
