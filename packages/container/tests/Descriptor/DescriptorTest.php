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
    );

    $restored = ComponentDescriptor::fromArray($desc->toArray());

    expect($restored)->toEqual($desc)
        ->and($restored->beans[0])->toBeInstanceOf(BeanDescriptor::class)
        ->and($restored->beans[0]->scope)->toBe(Scope::Transient)
        ->and($restored->scope)->toBe(Scope::Singleton);
});
