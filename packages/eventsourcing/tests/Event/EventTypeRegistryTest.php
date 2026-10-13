<?php

declare(strict_types=1);

use Firefly\EventSourcing\Event\EventTypeDescriptor;
use Firefly\EventSourcing\Event\EventTypeRegistry;
use Firefly\EventSourcing\Exception\UnknownEventTypeException;
use Firefly\EventSourcing\Tests\Fixtures\Events\OrderPaid;
use Firefly\EventSourcing\Tests\Fixtures\Events\OrderPlaced;
use Firefly\EventSourcing\Tests\Fixtures\Events\Untyped;
use Firefly\Kernel\Exception\Framework\ConfigurationException;

it('looks events up by class and by type, and accepts an identical registration twice', function (): void {
    $placed = new EventTypeDescriptor(OrderPlaced::class, 'order.placed', 2);
    $registry = new EventTypeRegistry([$placed]);
    $registry->register($placed);

    expect($registry->forClass(OrderPlaced::class))->toBe($placed)
        ->and($registry->forType('order.placed'))->toBe($placed)
        ->and($registry->has('order.placed'))->toBeTrue()
        ->and($registry->all())->toBe([$placed])
        ->and(fn () => $registry->forClass(Untyped::class))->toThrow(UnknownEventTypeException::class, 'firefly:cache')
        ->and(fn () => $registry->forType('order.lost'))->toThrow(UnknownEventTypeException::class, 'order.lost');
});

it('refuses a type claimed by two classes and a class registered under two types', function (): void {
    $registry = new EventTypeRegistry([new EventTypeDescriptor(OrderPlaced::class, 'order.placed', 2)]);

    expect(fn () => $registry->register(new EventTypeDescriptor(OrderPaid::class, 'order.placed')))->toThrow(ConfigurationException::class, 'order.placed')
        ->and(fn () => $registry->register(new EventTypeDescriptor(OrderPlaced::class, 'order.created', 2)))->toThrow(ConfigurationException::class, OrderPlaced::class);
});
