<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Config\Profile\Profiles;
use Firefly\Container\Descriptor\BeanDescriptor;
use Firefly\Container\Descriptor\ComponentDescriptor;
use Firefly\Container\Scope;
use Firefly\Context\Condition\Attributes\ConditionalOnBean;
use Firefly\Context\Condition\Attributes\ConditionalOnClass;
use Firefly\Context\Condition\Attributes\ConditionalOnMissingBean;
use Firefly\Context\Condition\Attributes\ConditionalOnMissingClass;
use Firefly\Context\Condition\Attributes\ConditionalOnProfile;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Firefly\Context\Condition\ConditionEvaluator;
use Firefly\Context\Definition\BeanDefinition;
use Firefly\Context\Definition\BeanDefinitionRegistry;
use Firefly\Context\Definition\DefinitionSource;
use Firefly\Context\Tests\Fixtures\Cache;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Illuminate\Config\Repository;

/**
 * @param  array<string, mixed>  $items
 */
function contextConfig(array $items): Config
{
    return new Config(new Repository($items));
}

/**
 * @param  array<string, mixed>  $configItems
 * @param  list<string>  $activeProfiles
 */
function makeEvaluator(array $configItems = [], array $activeProfiles = []): ConditionEvaluator
{
    return new ConditionEvaluator(contextConfig($configItems), new Profiles($activeProfiles));
}

/**
 * @param  list<class-string>  $interfaces
 * @param  list<BeanDescriptor>  $beans
 */
function evaluatorDescriptor(string $class, array $interfaces = [], array $beans = []): ComponentDescriptor
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
        beans: $beans,
    );
}

// --- #[ConditionalOnProperty] ---

it('matches #[ConditionalOnProperty] when the value string-equals havingValue', function () {
    $evaluator = makeEvaluator(['firefly' => ['cache' => ['enabled' => 'true']]]);
    $outcome = $evaluator->evaluate(new ConditionalOnProperty('firefly.cache.enabled', havingValue: 'true'));

    expect($outcome->matched)->toBeTrue();
});

it('does not match #[ConditionalOnProperty] when the value differs from havingValue, naming the observed value', function () {
    $evaluator = makeEvaluator(['firefly' => ['cache' => ['enabled' => 'false']]]);
    $outcome = $evaluator->evaluate(new ConditionalOnProperty('firefly.cache.enabled', havingValue: 'true'));

    expect($outcome->matched)->toBeFalse()
        ->and($outcome->reason)->toBe("@ConditionalOnProperty (firefly.cache.enabled=false) did not match required value 'true'");
});

it('treats a present-but-null property value as MISSING (M3 consistency rule), applying matchIfMissing', function () {
    $evaluator = makeEvaluator(['firefly' => ['cache' => ['enabled' => null]]]);

    $whenTrue = $evaluator->evaluate(new ConditionalOnProperty('firefly.cache.enabled', matchIfMissing: true));
    $whenFalse = $evaluator->evaluate(new ConditionalOnProperty('firefly.cache.enabled', matchIfMissing: false));

    expect($whenTrue->matched)->toBeTrue()
        ->and($whenFalse->matched)->toBeFalse();
});

it('applies matchIfMissing when the key is entirely absent', function () {
    $evaluator = makeEvaluator([]);

    expect($evaluator->evaluate(new ConditionalOnProperty('firefly.cache.enabled', matchIfMissing: true))->matched)->toBeTrue()
        ->and($evaluator->evaluate(new ConditionalOnProperty('firefly.cache.enabled', matchIfMissing: false))->matched)->toBeFalse();
});

it('matches #[ConditionalOnProperty] with no havingValue unless the value is falsy-ish', function () {
    expect(makeEvaluator(['firefly' => ['x' => 'anything']])->evaluate(new ConditionalOnProperty('firefly.x'))->matched)->toBeTrue()
        ->and(makeEvaluator(['firefly' => ['x' => 'false']])->evaluate(new ConditionalOnProperty('firefly.x'))->matched)->toBeFalse()
        ->and(makeEvaluator(['firefly' => ['x' => 0]])->evaluate(new ConditionalOnProperty('firefly.x'))->matched)->toBeFalse()
        ->and(makeEvaluator(['firefly' => ['x' => '0']])->evaluate(new ConditionalOnProperty('firefly.x'))->matched)->toBeFalse()
        ->and(makeEvaluator(['firefly' => ['x' => '']])->evaluate(new ConditionalOnProperty('firefly.x'))->matched)->toBeFalse()
        ->and(makeEvaluator(['firefly' => ['x' => false]])->evaluate(new ConditionalOnProperty('firefly.x'))->matched)->toBeFalse();
});

// --- #[ConditionalOnClass] / #[ConditionalOnMissingClass] ---

it('matches #[ConditionalOnClass] when the class is loadable', function () {
    expect(makeEvaluator()->evaluate(new ConditionalOnClass(Config::class))->matched)->toBeTrue();
});

it('does not match #[ConditionalOnClass] when the class is absent, without throwing', function () {
    $outcome = makeEvaluator()->evaluate(new ConditionalOnClass('Some\Vendor\Package\Absent'));

    expect($outcome->matched)->toBeFalse();
});

it('matches #[ConditionalOnMissingClass] when the class is absent', function () {
    expect(makeEvaluator()->evaluate(new ConditionalOnMissingClass('Some\Vendor\Package\Absent'))->matched)->toBeTrue();
});

it('does not match #[ConditionalOnMissingClass] when the class is loadable', function () {
    expect(makeEvaluator()->evaluate(new ConditionalOnMissingClass(Config::class))->matched)->toBeFalse();
});

// --- #[ConditionalOnProfile] ---

it('matches #[ConditionalOnProfile] when any listed profile is active', function () {
    $evaluator = makeEvaluator([], ['local', 'testing']);

    expect($evaluator->evaluate(new ConditionalOnProfile('production', 'testing'))->matched)->toBeTrue();
});

it('does not match #[ConditionalOnProfile] when none of the listed profiles are active', function () {
    $evaluator = makeEvaluator([], ['local']);

    expect($evaluator->evaluate(new ConditionalOnProfile('production', 'staging'))->matched)->toBeFalse();
});

// --- #[ConditionalOnBean] / #[ConditionalOnMissingBean] ---

it('matches #[ConditionalOnBean] via a class-name definition', function () {
    $registry = new BeanDefinitionRegistry;
    $registry->add(new BeanDefinition(evaluatorDescriptor('App\CacheManager'), source: DefinitionSource::AutoConfiguration));

    expect(makeEvaluator()->evaluate(new ConditionalOnBean('App\CacheManager'), $registry)->matched)->toBeTrue();
});

it('matches #[ConditionalOnBean] via an implemented interface', function () {
    $registry = new BeanDefinitionRegistry;
    $registry->add(new BeanDefinition(
        evaluatorDescriptor('App\RedisCache', interfaces: [Cache::class]),
        source: DefinitionSource::AutoConfiguration,
    ));

    expect(makeEvaluator()->evaluate(new ConditionalOnBean(Cache::class), $registry)->matched)->toBeTrue();
});

it('matches #[ConditionalOnBean] via a #[Bean] method return type', function () {
    $registry = new BeanDefinitionRegistry;
    $registry->add(new BeanDefinition(
        evaluatorDescriptor('App\Config', beans: [
            new BeanDescriptor('cacheBean', Cache::class, null, Scope::Singleton, false, 0),
        ]),
        source: DefinitionSource::AutoConfiguration,
    ));

    expect(makeEvaluator()->evaluate(new ConditionalOnBean(Cache::class), $registry)->matched)->toBeTrue();
});

it('does not match #[ConditionalOnBean] when no route makes the type available', function () {
    $registry = new BeanDefinitionRegistry;

    expect(makeEvaluator()->evaluate(new ConditionalOnBean(Cache::class), $registry)->matched)->toBeFalse();
});

it('matches #[ConditionalOnMissingBean] when absent, and fails to match once the type is present', function () {
    $registry = new BeanDefinitionRegistry;
    $evaluator = makeEvaluator();

    expect($evaluator->evaluate(new ConditionalOnMissingBean(Cache::class), $registry)->matched)->toBeTrue();

    $registry->add(new BeanDefinition(evaluatorDescriptor('App\RedisCache', interfaces: [Cache::class]), source: DefinitionSource::AutoConfiguration));

    expect($evaluator->evaluate(new ConditionalOnMissingBean(Cache::class), $registry)->matched)->toBeFalse();
});

it('throws ConfigurationException evaluating a bean condition without a registry (a pipeline bug, not a user error)', function () {
    makeEvaluator()->evaluate(new ConditionalOnBean(Cache::class));
})->throws(ConfigurationException::class);

// --- matches(): AND semantics across multiple conditions ---

it('matches() requires ALL conditions of the current phase to match (AND semantics)', function () {
    $evaluator = makeEvaluator(['firefly' => ['a' => 'true', 'b' => 'false']]);
    $definition = new BeanDefinition(
        evaluatorDescriptor('App\Thing'),
        conditions: [
            new ConditionalOnProperty('firefly.a', havingValue: 'true'),
            new ConditionalOnProperty('firefly.b', havingValue: 'true'),
        ],
    );

    expect($evaluator->matches($definition, beanPhase: false, registry: new BeanDefinitionRegistry))->toBeFalse();
});

it('matches() passes when every current-phase condition matches', function () {
    $evaluator = makeEvaluator(['firefly' => ['a' => 'true', 'b' => 'true']]);
    $definition = new BeanDefinition(
        evaluatorDescriptor('App\Thing'),
        conditions: [
            new ConditionalOnProperty('firefly.a', havingValue: 'true'),
            new ConditionalOnProperty('firefly.b', havingValue: 'true'),
        ],
    );

    expect($evaluator->matches($definition, beanPhase: false, registry: new BeanDefinitionRegistry))->toBeTrue();
});

// --- phase partition: never re-evaluate a pass-one condition in pass two, and vice versa ---

it('does NOT evaluate a bean condition during pass one', function () {
    $evaluator = makeEvaluator();
    // AutoConfiguration source so the user-component rule cannot interfere with this test.
    $definition = new BeanDefinition(
        evaluatorDescriptor('App\AutoThing'),
        conditions: [new ConditionalOnBean(Cache::class)],
        source: DefinitionSource::AutoConfiguration,
    );

    // Pass one (beanPhase: false): the ONLY condition is a bean condition, so it must be skipped
    // entirely — the definition matches vacuously, regardless of what the (empty) registry holds.
    expect($evaluator->matches($definition, beanPhase: false, registry: new BeanDefinitionRegistry))->toBeTrue();
});

it('does NOT evaluate a non-bean condition during pass two', function () {
    $evaluator = makeEvaluator(['firefly' => ['flag' => 'false']]);
    $definition = new BeanDefinition(
        evaluatorDescriptor('App\AutoThing'),
        // Would fail if (wrongly) evaluated: firefly.flag=false does not equal 'true'.
        conditions: [new ConditionalOnProperty('firefly.flag', havingValue: 'true')],
        source: DefinitionSource::AutoConfiguration,
    );

    // Pass two (beanPhase: true): the ONLY condition is a non-bean condition, so it is skipped —
    // the definition matches vacuously.
    expect($evaluator->matches($definition, beanPhase: true, registry: new BeanDefinitionRegistry))->toBeTrue();
});

// --- the user-component rule ---

it('raises ConfigurationException for #[ConditionalOnMissingBean] on a USER component, naming class and attribute', function () {
    $evaluator = makeEvaluator();
    $definition = new BeanDefinition(
        evaluatorDescriptor('App\Foo'),
        conditions: [new ConditionalOnMissingBean(Cache::class)],
        source: DefinitionSource::User,
    );

    expect(fn () => $evaluator->matches($definition, beanPhase: true, registry: new BeanDefinitionRegistry))
        ->toThrow(
            ConfigurationException::class,
            '#[ConditionalOnMissingBean] is not supported on user component [App\Foo] — bean conditions '.
            'are only valid on auto-configurations, which run after all user beans. Use #[ConditionalOnProperty] '.
            'or #[ConditionalOnClass] instead.',
        );
});

it('raises the ConfigurationException for a user-component bean condition even when evaluated during pass one', function () {
    $evaluator = makeEvaluator();
    $definition = new BeanDefinition(
        evaluatorDescriptor('App\Foo'),
        conditions: [new ConditionalOnBean(Cache::class)],
        source: DefinitionSource::User,
    );

    expect(fn () => $evaluator->matches($definition, beanPhase: false, registry: new BeanDefinitionRegistry))
        ->toThrow(ConfigurationException::class);
});

it('does NOT throw for a bean condition on an AutoConfiguration definition', function () {
    $evaluator = makeEvaluator();
    $definition = new BeanDefinition(
        evaluatorDescriptor('App\AutoCacheConfig'),
        conditions: [new ConditionalOnBean(Cache::class)],
        source: DefinitionSource::AutoConfiguration,
    );

    // No exception; the condition simply fails to match because nothing registered the type.
    expect($evaluator->matches($definition, beanPhase: true, registry: new BeanDefinitionRegistry))->toBeFalse();
});
