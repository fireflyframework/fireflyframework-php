<?php

declare(strict_types=1);

use Firefly\Container\Descriptor\BeanDescriptor;
use Firefly\Container\Descriptor\ComponentDescriptor;
use Firefly\Container\Scope;

it('round-trips a ComponentDescriptor through toArray/fromArray', function () {
    $bean = new BeanDescriptor('clock', 'App\\Clock', 'clock', Scope::Transient, true, 5);
    $desc = new ComponentDescriptor(
        class: 'App\\Greeter',
        stereotype: 'service',
        name: 'greeter',
        scope: Scope::Singleton,
        primary: false,
        order: 0,
        qualifier: 'main',
        interfaces: [Stringable::class],
        beans: [$bean],
        lazy: true,
    );

    $restored = ComponentDescriptor::fromArray($desc->toArray());

    expect($restored)->toEqual($desc)
        ->and($restored->beans[0])->toBeInstanceOf(BeanDescriptor::class)
        ->and($restored->beans[0]->scope)->toBe(Scope::Transient)
        ->and($restored->scope)->toBe(Scope::Singleton)
        ->and($restored->lazy)->toBeTrue();
});

it('round-trips a BeanDescriptor through toArray/fromArray', function () {
    $bean = new BeanDescriptor('clock', 'App\\Clock', 'clock', Scope::Scoped, false, 3);
    expect(BeanDescriptor::fromArray($bean->toArray()))->toEqual($bean);
});

it('round-trips a #[Lazy] BeanDescriptor through toArray/fromArray', function () {
    $bean = new BeanDescriptor('clock', 'App\\Clock', 'clock', Scope::Scoped, false, 3, lazy: true);

    $restored = BeanDescriptor::fromArray($bean->toArray());

    expect($restored)->toEqual($bean)
        ->and($restored->lazy)->toBeTrue();
});

it('defaults $lazy to false when a BeanDescriptor is constructed without it', function () {
    $bean = new BeanDescriptor('clock', 'App\\Clock', 'clock', Scope::Scoped, false, 3);

    expect($bean->lazy)->toBeFalse();
});

it('fromArray() defaults lazy to false for an OLD-SHAPE bean row with no "lazy" key (backward compatibility with a manifest cached before #[Lazy] support shipped)', function () {
    $oldShape = [
        'method' => 'clock',
        'returns' => 'App\\Clock',
        'name' => 'clock',
        'scope' => 'Scoped',
        'primary' => false,
        'order' => 3,
        // deliberately no 'lazy' key.
    ];

    $restored = BeanDescriptor::fromArray($oldShape);

    expect($restored->lazy)->toBeFalse();
});

it('defaults $lazy to false when a ComponentDescriptor is constructed without it', function () {
    $desc = new ComponentDescriptor(
        class: 'App\\Greeter',
        stereotype: 'service',
        name: null,
        scope: Scope::Singleton,
        primary: false,
        order: 0,
        qualifier: null,
        interfaces: [],
        beans: [],
    );

    expect($desc->lazy)->toBeFalse();
});

it('fromArray() defaults lazy to false for an OLD-SHAPE array with no "lazy" key (backward compatibility with a manifest cached before #[Lazy] support shipped)', function () {
    $oldShape = [
        'class' => 'App\\Greeter',
        'stereotype' => 'service',
        'name' => null,
        'scope' => 'Singleton',
        'primary' => false,
        'order' => 0,
        'qualifier' => null,
        'interfaces' => [],
        'beans' => [],
        // deliberately no 'lazy' key.
    ];

    $restored = ComponentDescriptor::fromArray($oldShape);

    expect($restored->lazy)->toBeFalse();
});
