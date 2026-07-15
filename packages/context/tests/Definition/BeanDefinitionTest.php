<?php

declare(strict_types=1);

use Firefly\Container\Descriptor\BeanDescriptor;
use Firefly\Container\Descriptor\ComponentDescriptor;
use Firefly\Container\Scope;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Firefly\Context\Definition\BeanDefinition;
use Firefly\Context\Definition\DefinitionSource;
use Firefly\Context\Tests\Fixtures\Cache;

/**
 * @param  list<BeanDescriptor>  $beans
 */
function withoutBeanMethodsDescriptor(string $class, array $beans): ComponentDescriptor
{
    return new ComponentDescriptor(
        class: $class,
        stereotype: 'Service',
        name: null,
        scope: Scope::Singleton,
        primary: false,
        order: 0,
        qualifier: null,
        interfaces: [],
        beans: $beans,
    );
}

function withoutBeanMethodsBean(string $method): BeanDescriptor
{
    return new BeanDescriptor($method, 'App\\'.ucfirst($method), null, Scope::Singleton, false, 0);
}

it('defaults beanConditions to an empty array', function () {
    $definition = new BeanDefinition(withoutBeanMethodsDescriptor('App\Thing', []));

    expect($definition->beanConditions)->toBe([]);
});

it('withoutBeanMethods() returns the SAME instance (not merely an equal one) when given an empty list', function () {
    $definition = new BeanDefinition(withoutBeanMethodsDescriptor('App\Thing', [
        withoutBeanMethodsBean('a'),
    ]));

    expect($definition->withoutBeanMethods([]))->toBe($definition);
});

it('withoutBeanMethods() removes only the named method(s), preserving every other descriptor field', function () {
    $descriptor = new ComponentDescriptor(
        class: 'App\Config',
        stereotype: 'Configuration',
        name: 'myConfig',
        scope: Scope::Singleton,
        primary: true,
        order: 7,
        qualifier: 'special',
        interfaces: [Cache::class],
        beans: [
            withoutBeanMethodsBean('a'),
            withoutBeanMethodsBean('b'),
            withoutBeanMethodsBean('c'),
        ],
        lazy: true,
    );
    $definition = new BeanDefinition(
        $descriptor,
        conditions: [new ConditionalOnProperty('firefly.on')],
        source: DefinitionSource::AutoConfiguration,
        beanConditions: ['a' => [new ConditionalOnProperty('firefly.a')]],
    );

    $filtered = $definition->withoutBeanMethods(['a', 'c']);

    expect($filtered)->not->toBe($definition)
        ->and(array_map(fn (BeanDescriptor $b): string => $b->method, $filtered->descriptor->beans))->toBe(['b'])
        ->and($filtered->descriptor->class)->toBe('App\Config')
        ->and($filtered->descriptor->stereotype)->toBe('Configuration')
        ->and($filtered->descriptor->name)->toBe('myConfig')
        ->and($filtered->descriptor->scope)->toBe(Scope::Singleton)
        ->and($filtered->descriptor->primary)->toBeTrue()
        ->and($filtered->descriptor->order)->toBe(7)
        ->and($filtered->descriptor->qualifier)->toBe('special')
        ->and($filtered->descriptor->interfaces)->toBe([Cache::class])
        ->and($filtered->descriptor->lazy)->toBeTrue()
        ->and($filtered->conditions)->toBe($definition->conditions)
        ->and($filtered->source)->toBe(DefinitionSource::AutoConfiguration)
        ->and($filtered->beanConditions)->toBe($definition->beanConditions);
});
