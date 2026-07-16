<?php

declare(strict_types=1);

use Firefly\AutoConfigure\Assembly\DefinitionAssembler;
use Firefly\Container\Descriptor\BeanDescriptor;
use Firefly\Container\Descriptor\ComponentDescriptor;
use Firefly\Container\Scanner\ComponentManifest;
use Firefly\Container\Scope;
use Firefly\Context\Condition\Attributes\ConditionalOnMissingBean;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Firefly\Context\Definition\DefinitionSource;
use Firefly\Context\Scanner\ContextDescriptor;
use Firefly\Context\Scanner\ContextManifest;

it('joins a component manifest and a context manifest into BeanDefinitions carrying class- and method-level conditions and the given source', function () {
    $components = new ComponentManifest([
        new ComponentDescriptor(
            class: 'App\\CacheAutoConfiguration', stereotype: 'configuration', name: null,
            scope: Scope::Singleton, primary: false, order: 1000, qualifier: null, interfaces: [],
            beans: [new BeanDescriptor('defaultCache', 'App\\CachePort', null, Scope::Singleton, false, 0, false)],
        ),
    ]);

    $context = new ContextManifest([
        new ContextDescriptor(
            class: 'App\\CacheAutoConfiguration',
            conditions: [['type' => ConditionalOnProperty::class, 'args' => ['firefly.cache.enabled', 'true', false]]],
            beanConditions: [['method' => 'defaultCache', 'conditions' => [['type' => ConditionalOnMissingBean::class, 'args' => ['App\\CachePort']]]]],
        ),
    ]);

    $definitions = (new DefinitionAssembler)->assemble($components, $context, DefinitionSource::AutoConfiguration);

    expect($definitions)->toHaveCount(1);
    $definition = $definitions[0];

    expect($definition->class())->toBe('App\\CacheAutoConfiguration')
        ->and($definition->source)->toBe(DefinitionSource::AutoConfiguration)
        ->and($definition->conditions)->toHaveCount(1)
        ->and($definition->beanConditions)->toHaveKey('defaultCache');

    // Class-level condition must be the ConditionalOnProperty (NOT the method-level
    // ConditionalOnMissingBean) — a swapped assembler would fail this instanceof narrowing.
    $classCondition = $definition->conditions[0];
    if (! $classCondition instanceof ConditionalOnProperty) {
        throw new RuntimeException('Expected a ConditionalOnProperty instance on $conditions.');
    }
    expect($classCondition->name)->toBe('firefly.cache.enabled');

    // Method-level condition must be the ConditionalOnMissingBean (NOT the class-level
    // ConditionalOnProperty) — proves $beanConditions and $conditions are not swapped/merged.
    $beanCondition = $definition->beanConditions['defaultCache'][0];
    if (! $beanCondition instanceof ConditionalOnMissingBean) {
        throw new RuntimeException('Expected a ConditionalOnMissingBean instance on $beanConditions.');
    }
    expect($beanCondition->type)->toBe('App\\CachePort');
});

it('emits an unconditional BeanDefinition when the class has no context descriptor', function () {
    $components = new ComponentManifest([
        new ComponentDescriptor(
            class: 'App\\PlainService', stereotype: 'service', name: null, scope: Scope::Singleton,
            primary: false, order: 0, qualifier: null, interfaces: [], beans: [],
        ),
    ]);

    $definitions = (new DefinitionAssembler)->assemble($components, new ContextManifest([]), DefinitionSource::User);

    expect($definitions)->toHaveCount(1)
        ->and($definitions[0]->conditions)->toBe([])
        ->and($definitions[0]->beanConditions)->toBe([])
        ->and($definitions[0]->source)->toBe(DefinitionSource::User);
});

it('keeps only #[Bean] methods that actually carry conditions in beanConditions, leaving unconditioned methods absent from the map', function () {
    // One variable under test: two #[Bean] methods on the same component, only one of which has a
    // method-level condition in the context descriptor. A buggy assembler that copies every bean
    // method into $beanConditions (even with an empty conditions list) would still pass a mere
    // toHaveKey('conditionedBean') check, so this asserts the OTHER method's key is absent too.
    $components = new ComponentManifest([
        new ComponentDescriptor(
            class: 'App\\MultiBeanAutoConfiguration', stereotype: 'configuration', name: null,
            scope: Scope::Singleton, primary: false, order: 0, qualifier: null, interfaces: [],
            beans: [
                new BeanDescriptor('conditionedBean', 'App\\PortA', null, Scope::Singleton, false, 0, false),
                new BeanDescriptor('plainBean', 'App\\PortB', null, Scope::Singleton, false, 0, false),
            ],
        ),
    ]);

    $context = new ContextManifest([
        new ContextDescriptor(
            class: 'App\\MultiBeanAutoConfiguration',
            conditions: [],
            beanConditions: [['method' => 'conditionedBean', 'conditions' => [['type' => ConditionalOnMissingBean::class, 'args' => ['App\\PortA']]]]],
        ),
    ]);

    $definition = (new DefinitionAssembler)->assemble($components, $context, DefinitionSource::AutoConfiguration)[0];

    expect($definition->beanConditions)->toHaveCount(1)
        ->and($definition->beanConditions)->toHaveKey('conditionedBean')
        ->and($definition->beanConditions)->not->toHaveKey('plainBean')
        ->and($definition->conditions)->toBe([]);
});

it('resolves each component against its OWN context descriptor independently, preserving list order', function () {
    // Two components: the first has a context descriptor with a class-level condition, the second
    // has none. This discriminates against an assembler that reuses the first-found descriptor for
    // every component, drops components without a descriptor, or reorders the output list.
    $components = new ComponentManifest([
        new ComponentDescriptor(
            class: 'App\\HasDescriptor', stereotype: 'configuration', name: null, scope: Scope::Singleton,
            primary: false, order: 0, qualifier: null, interfaces: [], beans: [],
        ),
        new ComponentDescriptor(
            class: 'App\\NoDescriptor', stereotype: 'service', name: null, scope: Scope::Singleton,
            primary: false, order: 0, qualifier: null, interfaces: [], beans: [],
        ),
    ]);

    $context = new ContextManifest([
        new ContextDescriptor(
            class: 'App\\HasDescriptor',
            conditions: [['type' => ConditionalOnProperty::class, 'args' => ['firefly.feature.enabled', null, false]]],
        ),
    ]);

    $definitions = (new DefinitionAssembler)->assemble($components, $context, DefinitionSource::User);

    expect($definitions)->toHaveCount(2)
        ->and($definitions[0]->class())->toBe('App\\HasDescriptor')
        ->and($definitions[0]->conditions)->toHaveCount(1)
        ->and($definitions[1]->class())->toBe('App\\NoDescriptor')
        ->and($definitions[1]->conditions)->toBe([]);
});
