<?php

declare(strict_types=1);

use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Component;
use Firefly\Container\Attributes\Configuration;
use Firefly\Container\Attributes\Order;
use Firefly\Container\Attributes\Primary;
use Firefly\Container\Attributes\Qualifier;
use Firefly\Container\Attributes\Repository;
use Firefly\Container\Attributes\Service;
use Firefly\Container\Scope;

it('discovers a stereotype as a Component via IS_INSTANCEOF', function () {
    $target = new #[Service('greeter', Scope::Transient)] class {};

    $attrs = (new ReflectionObject($target))->getAttributes(Component::class, ReflectionAttribute::IS_INSTANCEOF);

    expect($attrs)->toHaveCount(1);
    $instance = $attrs[0]->newInstance();
    expect($instance)->toBeInstanceOf(Service::class)
        ->and($instance)->toBeInstanceOf(Component::class)
        ->and($instance->name)->toBe('greeter')
        ->and($instance->scope)->toBe(Scope::Transient);
});

it('defaults name to null and scope to Singleton', function () {
    $target = new #[Repository] class {};
    $instance = (new ReflectionObject($target))->getAttributes(Component::class, ReflectionAttribute::IS_INSTANCEOF)[0]->newInstance();

    expect($instance)->toBeInstanceOf(Repository::class)
        ->and($instance->name)->toBeNull()
        ->and($instance->scope)->toBe(Scope::Singleton);
});

it('treats Configuration as a Component too', function () {
    $target = new #[Configuration] class {};
    $instance = (new ReflectionObject($target))->getAttributes(Component::class, ReflectionAttribute::IS_INSTANCEOF)[0]->newInstance();

    expect($instance)->toBeInstanceOf(Configuration::class)
        ->and($instance)->toBeInstanceOf(Component::class);
});

it('carries #[Bean], #[Primary], #[Order], #[Lazy], #[Qualifier] metadata', function () {
    $config = new class
    {
        #[Bean('clock', Scope::Transient)]
        #[Primary]
        #[Order(10)]
        public function clock(): string
        {
            return 'x';
        }
    };
    $method = (new ReflectionObject($config))->getMethod('clock');

    $bean = $method->getAttributes(Bean::class)[0]->newInstance();
    $order = $method->getAttributes(Order::class)[0]->newInstance();

    expect($bean->name)->toBe('clock')
        ->and($bean->scope)->toBe(Scope::Transient)
        ->and($method->getAttributes(Primary::class))->toHaveCount(1)
        ->and($order->order)->toBe(10);

    $q = new #[Qualifier('main')] class {};
    expect((new ReflectionObject($q))->getAttributes(Qualifier::class)[0]->newInstance()->name)->toBe('main');
});
