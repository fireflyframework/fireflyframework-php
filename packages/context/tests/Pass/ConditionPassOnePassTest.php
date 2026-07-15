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
 */
function passOneDescriptor(string $class, array $interfaces = []): ComponentDescriptor
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
