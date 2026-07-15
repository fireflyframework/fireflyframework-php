<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Config\Profile\Profiles;
use Firefly\Config\Value\ConfigValueResolver;
use Firefly\Container\Container as FireflyContainer;
use Firefly\Container\Descriptor\ComponentDescriptor;
use Firefly\Container\Scope;
use Firefly\Container\Value\ValueResolver;
use Firefly\Context\Boot\BootContext;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Firefly\Context\Condition\ConditionEvaluationReport;
use Firefly\Context\Condition\ConditionEvaluator;
use Firefly\Context\Definition\BeanDefinition;
use Firefly\Context\Definition\BeanDefinitionRegistry;
use Firefly\Context\Pass\ConditionPassOnePass;
use Firefly\Context\Pass\FlushDefinitionsPass;
use Firefly\Context\Tests\Fixtures\Cache;
use Firefly\Context\Tests\Fixtures\KeptWidgetOne;
use Firefly\Context\Tests\Fixtures\KeptWidgetTwo;
use Firefly\Context\Tests\Fixtures\RemovedWidget;
use Illuminate\Config\Repository;
use Illuminate\Container\Container as IlluminateContainer;

/**
 * @param  list<class-string>  $interfaces
 */
function flushDescriptor(string $class, array $interfaces = [], int $order = 0): ComponentDescriptor
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
        beans: [],
    );
}

/**
 * @param  array<string, mixed>  $configItems
 */
function flushContext(array $configItems = []): BootContext
{
    $config = new Config(new Repository($configItems));
    $profiles = new Profiles([]);

    return new BootContext(
        container: new IlluminateContainer,
        definitions: new BeanDefinitionRegistry,
        config: $config,
        profiles: $profiles,
        conditions: new ConditionEvaluator($config, $profiles),
        report: new ConditionEvaluationReport,
    );
}

it('flushes the condition-filtered manifest: registers only survivors, and the container can resolve a scanned component', function () {
    $context = flushContext(['firefly' => ['remove' => 'false']]);

    $context->definitions->add(new BeanDefinition(flushDescriptor(KeptWidgetOne::class, interfaces: [Cache::class], order: 5)));
    $context->definitions->add(new BeanDefinition(flushDescriptor(KeptWidgetTwo::class, interfaces: [Cache::class], order: 1)));
    $context->definitions->add(new BeanDefinition(
        flushDescriptor(RemovedWidget::class, interfaces: [Cache::class]),
        conditions: [new ConditionalOnProperty('firefly.remove', havingValue: 'true')],
    ));

    // Real pass one, real removal — no test double stands in for "a definition removed in pass one".
    (new ConditionPassOnePass)->run($context);

    (new FlushDefinitionsPass)->run($context);

    // The container can resolve a scanned (surviving) component.
    expect($context->container->make(KeptWidgetOne::class))->toBeInstanceOf(KeptWidgetOne::class);

    // Invariant 6 + facade-manifest identity: a definition removed in pass one is neither bound in
    // the container nor exposed via the facade's getAll() for its interface.
    expect($context->container->bound(KeptWidgetOne::class))->toBeTrue()
        ->and($context->container->bound(KeptWidgetTwo::class))->toBeTrue()
        ->and($context->container->bound(RemovedWidget::class))->toBeFalse();

    /** @var FireflyContainer $facade */
    $facade = $context->container->make(FireflyContainer::class);
    expect($facade)->toBeInstanceOf(FireflyContainer::class);

    $all = $facade->getAll(Cache::class);
    expect($all)->toHaveCount(2)
        ->and($all[0])->toBeInstanceOf(KeptWidgetTwo::class) // order 1, sorted first
        ->and($all[1])->toBeInstanceOf(KeptWidgetOne::class) // order 5, sorted second
        ->and(array_map(fn (object $o): string => $o::class, $all))->not->toContain(RemovedWidget::class);

    // ConfigRegistrar was actually wired inside this same flush point: it overrides M2's
    // DefaultValueResolver with the config-backed ConfigValueResolver.
    expect($context->container->make(ValueResolver::class))->toBeInstanceOf(ConfigValueResolver::class);
});

it('calls ContainerRegistrar::register() exactly once — a second Flush run is a no-op, not a duplicate registration', function () {
    $context = flushContext();
    $context->definitions->add(new BeanDefinition(flushDescriptor(KeptWidgetOne::class, interfaces: [Cache::class])));

    (new FlushDefinitionsPass)->run($context);
    (new FlushDefinitionsPass)->run($context); // simulate the pass running twice

    /** @var FireflyContainer $facade */
    $facade = $context->container->make(FireflyContainer::class);

    // If register() had actually run twice, Illuminate's tag() APPENDS rather than replaces, and
    // this would report 2 instances instead of 1.
    expect($facade->getAll(Cache::class))->toHaveCount(1);
});
