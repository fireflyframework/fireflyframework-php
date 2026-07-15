<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Config\Profile\Profiles;
use Firefly\Container\Descriptor\BeanDescriptor;
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
 * @param  list<BeanDescriptor>  $beans
 */
function passTwoDescriptor(string $class, array $interfaces = [], array $beans = [], int $order = 0): ComponentDescriptor
{
    return new ComponentDescriptor(
        class: $class,
        stereotype: 'Service',
        name: null,
        scope: Scope::Singleton,
        primary: false,
        order: $order,
        qualifier: null,
        interfaces: $interfaces,
        beans: $beans,
    );
}

/**
 * A #[Bean] factory method descriptor returning $returns — the route BeanDefinitionRegistry::
 * containsType() uses to see "this definition supplies type X" via its OWN #[Bean] method, exactly
 * like a real `#[Bean] public function defaultCache(): CachePort {...}` factory.
 */
function passTwoBean(string $method, string $returns): BeanDescriptor
{
    return new BeanDescriptor(
        method: $method,
        returns: $returns,
        name: null,
        scope: Scope::Singleton,
        primary: false,
        order: 0,
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
    // Explicit order: 0 then 1 — deliberately NOT relying on 'App\Existing' < 'App\Dependent'
    // alphabetically (it isn't: 'D' < 'E'), because evaluation order is now governed by
    // (order, FQCN), not by registry insertion order. Setting order explicitly keeps this test's
    // intent (existing is evaluated, and accepted, BEFORE dependent) independent of class naming.
    $existing = new BeanDefinition(
        passTwoDescriptor('App\Existing', interfaces: [Cache::class], order: 0),
        source: DefinitionSource::AutoConfiguration,
    );
    $dependent = new BeanDefinition(
        passTwoDescriptor('App\Dependent', order: 1),
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

it('is order-stable, NOT snapshot-stable: two definitions with mutually-referencing bean conditions deterministically keep the FIRST by (order, FQCN), regardless of registry insertion order', function () {
    // A is missing-bean on B's type; B is missing-bean on A's type — a mutual #[ConditionalOnMissingBean]
    // tie. This test used to assert BOTH were removed (a frozen batch snapshot sees each seeing the
    // other and drops both — the worst possible outcome: neither implementation survives, silently).
    // The incremental model instead sorts by (order, FQCN) BEFORE evaluating anything, then evaluates
    // each in turn against the registry as it currently stands: 'MutualA' < 'MutualB' by FQCN (both
    // default order 0), so A is evaluated FIRST against a registry that contains neither yet — its
    // condition (missing B) matches, A is added back. B is evaluated SECOND against a registry that
    // now contains A — its condition (missing A) fails, B is dropped. Exactly ONE survives, and which
    // one is a pure function of (order, FQCN) — NOT of which was added to the registry first.
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

    expect($remainingAB)->toBe([$classA])
        ->and($remainingBA)->toBe([$classA]);
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

// --- M4 design-bug regression suite: self-seeing, correct back-off, and mutual back-off / ---
// --- first-wins. Each reproduces the canonical Spring Boot starter shape verbatim: ---
// ---   #[Configuration] class CacheAutoConfiguration { ---
// ---       #[Bean] #[ConditionalOnMissingBean(CachePort::class)] ---
// ---       public function defaultCache(): CachePort { ... } ---
// ---   } ---
// --- represented here via passTwoBean() (a #[Bean] method returning Cache — the exact route ---
// --- BeanDefinitionRegistry::containsType() uses to see "this definition supplies Cache"). ---
// --- Each of these three tests FAILS against the pre-fix batch-filtering implementation. ---

it('self-seeing: an auto-config whose OWN #[Bean] supplies the type it also gates on IS registered when nothing else supplies the type', function () {
    // Under batch filtering this ALWAYS fails: BeanDefinitionRegistry::containsType() does not
    // exclude the definition currently being evaluated, so the snapshot (which includes this
    // definition's own contribution) reports Cache as "present" and the definition backs off from
    // ITSELF — forever, even with zero competing beans. The fix removes every candidate from the
    // registry before evaluating any of them, so a definition's own #[Bean] contribution is never
    // visible while its own condition is being decided.
    $cacheAutoConfiguration = new BeanDefinition(
        passTwoDescriptor('App\CacheAutoConfiguration', beans: [passTwoBean('defaultCache', Cache::class)]),
        conditions: [new ConditionalOnMissingBean(Cache::class)],
        source: DefinitionSource::AutoConfiguration,
    );

    $context = passTwoContext();
    $context->definitions->add($cacheAutoConfiguration);

    (new ConditionPassTwoPass)->run($context);

    $remaining = array_map(fn (BeanDefinition $d): string => $d->class(), $context->definitions->all());
    expect($remaining)->toBe(['App\CacheAutoConfiguration']);

    $entries = $context->report->all();
    expect($entries)->toHaveCount(1)
        ->and($entries[0]['class'])->toBe('App\CacheAutoConfiguration')
        ->and($entries[0]['attribute'])->toBe(ConditionalOnMissingBean::class)
        ->and($entries[0]['outcome']->matched)->toBeTrue();
});

it('correct back-off: the same auto-config IS removed when a user bean supplies the type — the starter mechanism actually working', function () {
    $userCache = new BeanDefinition(
        passTwoDescriptor('App\UserCache', interfaces: [Cache::class]),
        source: DefinitionSource::User,
    );
    $cacheAutoConfiguration = new BeanDefinition(
        passTwoDescriptor('App\CacheAutoConfiguration', beans: [passTwoBean('defaultCache', Cache::class)]),
        conditions: [new ConditionalOnMissingBean(Cache::class)],
        source: DefinitionSource::AutoConfiguration,
    );

    $context = passTwoContext();
    $context->definitions->add($userCache);
    $context->definitions->add($cacheAutoConfiguration);

    (new ConditionPassTwoPass)->run($context);

    $remaining = array_map(fn (BeanDefinition $d): string => $d->class(), $context->definitions->all());
    expect($remaining)->toBe(['App\UserCache']);

    $entries = $context->report->all();
    expect($entries)->toHaveCount(1)
        ->and($entries[0]['class'])->toBe('App\CacheAutoConfiguration')
        ->and($entries[0]['attribute'])->toBe(ConditionalOnMissingBean::class)
        ->and($entries[0]['outcome']->matched)->toBeFalse();
});

it('mutual back-off / first-wins: two auto-configs both supplying the same type, both #[ConditionalOnMissingBean]-gated on it, leave EXACTLY ONE survivor — deterministically the lower (order, FQCN), regardless of registry insertion order', function () {
    // Under batch filtering BOTH are removed (each sees the other's contribution in the frozen
    // snapshot) — the worst outcome: the user gets neither cache implementation and no error
    // explaining why. The fix sorts by (order, FQCN) before evaluating anything, so exactly one
    // (the first in that order) is accepted and visible to the other.
    $aAutoConfiguration = new BeanDefinition(
        passTwoDescriptor('App\AAutoConfiguration', beans: [passTwoBean('cache', Cache::class)]),
        conditions: [new ConditionalOnMissingBean(Cache::class)],
        source: DefinitionSource::AutoConfiguration,
    );
    $bAutoConfiguration = new BeanDefinition(
        passTwoDescriptor('App\BAutoConfiguration', beans: [passTwoBean('cache', Cache::class)]),
        conditions: [new ConditionalOnMissingBean(Cache::class)],
        source: DefinitionSource::AutoConfiguration,
    );

    $remainingAThenB = runPassTwoAndCollectRemaining([$aAutoConfiguration, $bAutoConfiguration]);
    $remainingBThenA = runPassTwoAndCollectRemaining([$bAutoConfiguration, $aAutoConfiguration]);

    // 'App\AAutoConfiguration' < 'App\BAutoConfiguration' by FQCN (both order 0) — A is the
    // deterministic survivor in BOTH registry insertion orders.
    expect($remainingAThenB)->toBe(['App\AAutoConfiguration'])
        ->and($remainingBThenA)->toBe(['App\AAutoConfiguration']);
});

it('mutual back-off honors #[Order] BEFORE FQCN: a lower #[Order] wins even with an alphabetically later class name', function () {
    $higherOrderEarlierName = new BeanDefinition(
        passTwoDescriptor('App\AAutoConfiguration', beans: [passTwoBean('cache', Cache::class)], order: 5),
        conditions: [new ConditionalOnMissingBean(Cache::class)],
        source: DefinitionSource::AutoConfiguration,
    );
    $lowerOrderLaterName = new BeanDefinition(
        passTwoDescriptor('App\ZAutoConfiguration', beans: [passTwoBean('cache', Cache::class)], order: -5),
        conditions: [new ConditionalOnMissingBean(Cache::class)],
        source: DefinitionSource::AutoConfiguration,
    );

    $remaining = runPassTwoAndCollectRemaining([$higherOrderEarlierName, $lowerOrderLaterName]);

    expect($remaining)->toBe(['App\ZAutoConfiguration']);
});
