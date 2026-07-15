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
use Firefly\Context\Pass\ConditionPassOnePass;
use Firefly\Context\Tests\Fixtures\Cache;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;

/**
 * @param  array<string, mixed>  $items
 */
function passOneConfig(array $items = []): Config
{
    return new Config(new Repository($items));
}

/**
 * @param  list<class-string>  $interfaces
 * @param  list<BeanDescriptor>  $beans
 */
function passOneDescriptor(string $class, array $interfaces = [], array $beans = []): ComponentDescriptor
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

/**
 * A #[Bean] factory method descriptor, mirroring passTwoBean() in ConditionPassTwoPassTest.
 */
function passOneBean(string $method, string $returns): BeanDescriptor
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
 * @return list<string>
 */
function beanMethodsOf(BeanDefinition $definition): array
{
    return array_map(static fn (BeanDescriptor $bean): string => $bean->method, $definition->descriptor->beans);
}

/**
 * @param  array<string, mixed>  $configItems
 */
function passOneContext(array $configItems = []): BootContext
{
    $config = passOneConfig($configItems);
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

it('removes a definition whose #[ConditionalOnProperty] does not match, keeps one that does, and records both outcomes', function () {
    $context = passOneContext(['firefly' => ['keep' => 'true', 'remove' => 'false']]);

    $kept = new BeanDefinition(
        passOneDescriptor('App\Kept'),
        conditions: [new ConditionalOnProperty('firefly.keep', havingValue: 'true')],
    );
    $removed = new BeanDefinition(
        passOneDescriptor('App\Removed'),
        conditions: [new ConditionalOnProperty('firefly.remove', havingValue: 'true')],
    );
    $context->definitions->add($kept);
    $context->definitions->add($removed);

    (new ConditionPassOnePass)->run($context);

    $remainingClasses = array_map(fn (BeanDefinition $d): string => $d->class(), $context->definitions->all());
    expect($remainingClasses)->toBe(['App\Kept']);

    $entries = $context->report->all();
    expect($entries)->toHaveCount(2);

    $byClass = [];
    foreach ($entries as $entry) {
        $byClass[$entry['class']] = $entry;
    }

    expect($byClass['App\Kept']['outcome']->matched)->toBeTrue()
        ->and($byClass['App\Kept']['attribute'])->toBe(ConditionalOnProperty::class)
        ->and($byClass['App\Removed']['outcome']->matched)->toBeFalse()
        ->and($byClass['App\Removed']['outcome']->reason)->toContain('firefly.remove');
});

it('does NOT evaluate bean conditions — a definition with only a bean condition is left untouched and unreported', function () {
    $context = passOneContext();

    $definition = new BeanDefinition(
        passOneDescriptor('App\AutoThing'),
        conditions: [new ConditionalOnMissingBean(Cache::class)],
        source: DefinitionSource::AutoConfiguration,
    );
    $context->definitions->add($definition);

    (new ConditionPassOnePass)->run($context);

    expect($context->definitions->all())->toHaveCount(1)
        ->and($context->definitions->all()[0])->toBe($definition)
        ->and($context->report->all())->toBe([]);
});

it('does NOT touch a DefinitionSource::AutoConfiguration definition\'s REGISTRY-INDEPENDENT condition either — that is ConditionPassTwoPass\'s job now', function () {
    // Scoped to DefinitionSource::User by design (see the class docblock): an AutoConfiguration
    // definition is left completely alone here, even one carrying a non-bean condition that would
    // fail if evaluated — proving the scope is by SOURCE, not merely by condition type.
    $context = passOneContext(['firefly' => ['autoconfig' => ['on' => false]]]);

    $definition = new BeanDefinition(
        passOneDescriptor('App\AutoThing'),
        conditions: [new ConditionalOnProperty('firefly.autoconfig.on', havingValue: 'true')],
        source: DefinitionSource::AutoConfiguration,
    );
    $context->definitions->add($definition);

    (new ConditionPassOnePass)->run($context);

    expect($context->definitions->all())->toHaveCount(1)
        ->and($context->definitions->all()[0])->toBe($definition)
        ->and($context->report->all())->toBe([]);
});

it('propagates ConfigurationException, unsoftened, for a bean condition on a DefinitionSource::User definition', function () {
    // ConditionPassOnePass is now the pass that actually processes User definitions in the real
    // pipeline (it runs after UserConfigurations, before AutoConfigurations even exist), so this is
    // where the pipeline first — and only — ever encounters this structural error.
    $context = passOneContext();
    $context->definitions->add(new BeanDefinition(
        passOneDescriptor('App\UserThing'),
        conditions: [new ConditionalOnMissingBean(Cache::class)],
        source: DefinitionSource::User,
    ));

    (new ConditionPassOnePass)->run($context);
})->throws(ConfigurationException::class);

// --- Critical 1 regression: method-level #[ConditionalOn*] on a #[Bean] method ---
//
// A method-level condition failing to match must remove ONLY that #[Bean] method from the
// descriptor's `beans` list — never the whole definition (that would over-remove; a #[Configuration]
// class routinely has other, unrelated #[Bean] methods, or is itself also a #[Component]). Before
// this fix, ConditionPassOnePass never even looked at BeanDefinition::$beanConditions: a #[Bean]
// method's own #[ConditionalOn*] was captured by ContextScanner, stored, documented as working
// (docs/modules/context.md:106), and simply never consumed — the definition (and every one of its
// #[Bean] methods) always survived condition evaluation completely untouched.

it('removes ONLY the #[Bean] method whose method-level #[ConditionalOnProperty] does not match, keeping the definition and its other #[Bean] methods (Critical 1 regression)', function () {
    $context = passOneContext();

    $definition = new BeanDefinition(
        passOneDescriptor('App\PaymentsConfig', beans: [
            passOneBean('gatedBean', 'App\GatedType'),
            passOneBean('keptBean', 'App\KeptType'),
        ]),
        beanConditions: [
            'gatedBean' => [new ConditionalOnProperty('firefly.never.set')],
        ],
    );
    $context->definitions->add($definition);

    (new ConditionPassOnePass)->run($context);

    $remaining = $context->definitions->all();
    expect($remaining)->toHaveCount(1)
        ->and($remaining[0]->class())->toBe('App\PaymentsConfig')
        ->and(beanMethodsOf($remaining[0]))->toBe(['keptBean']);

    $entries = $context->report->all();
    expect($entries)->toHaveCount(1)
        ->and($entries[0]['class'])->toBe('App\PaymentsConfig::gatedBean()')
        ->and($entries[0]['attribute'])->toBe(ConditionalOnProperty::class)
        ->and($entries[0]['outcome']->matched)->toBeFalse();
});

it('keeps a #[Bean] method whose method-level condition DOES match, and records the match', function () {
    $context = passOneContext(['firefly' => ['on' => 'true']]);

    $definition = new BeanDefinition(
        passOneDescriptor('App\PaymentsConfig', beans: [
            passOneBean('gatedBean', 'App\GatedType'),
        ]),
        beanConditions: [
            'gatedBean' => [new ConditionalOnProperty('firefly.on', havingValue: 'true')],
        ],
    );
    $context->definitions->add($definition);

    (new ConditionPassOnePass)->run($context);

    $remaining = $context->definitions->all();
    expect($remaining)->toHaveCount(1)
        ->and(beanMethodsOf($remaining[0]))->toBe(['gatedBean']);

    $entries = $context->report->all();
    expect($entries)->toHaveCount(1)
        ->and($entries[0]['class'])->toBe('App\PaymentsConfig::gatedBean()')
        ->and($entries[0]['outcome']->matched)->toBeTrue();
});

it('propagates ConfigurationException for a method-level bean condition on a DefinitionSource::User definition', function () {
    $context = passOneContext();
    $context->definitions->add(new BeanDefinition(
        passOneDescriptor('App\UserConfig', beans: [
            passOneBean('gatedBean', 'App\GatedType'),
        ]),
        beanConditions: [
            'gatedBean' => [new ConditionalOnMissingBean(Cache::class)],
        ],
    ));

    (new ConditionPassOnePass)->run($context);
})->throws(ConfigurationException::class);
