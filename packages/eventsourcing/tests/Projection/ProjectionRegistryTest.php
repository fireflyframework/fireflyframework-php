<?php

declare(strict_types=1);

use Firefly\EventSourcing\Event\EventMetadata;
use Firefly\EventSourcing\Event\StoredEvent;
use Firefly\EventSourcing\Exception\EventSourcingException;
use Firefly\EventSourcing\Projection\FunctionProjection;
use Firefly\EventSourcing\Projection\Projection;
use Firefly\EventSourcing\Projection\ProjectionRegistry;
use Firefly\Kernel\Exception\Framework\ConfigurationException;

function projectionRegistryEvent(): StoredEvent
{
    $at = new DateTimeImmutable('2026-10-13 10:00:00');

    return new StoredEvent('e-1', 'o-1', 'order', 1, 1, 'order.placed', 1, ['orderId' => 'o-1'], new EventMetadata, $at, $at);
}

it('keeps projections by name and hands a function projection its events and its reset', function (): void {
    $seen = [];
    $resets = 0;
    $totals = new FunctionProjection('orders.totals', static function (StoredEvent $event) use (&$seen): void {
        $seen[] = $event->eventType;
    }, ['order.placed'], static function () use (&$resets): void {
        $resets++;
    });
    $audit = new FunctionProjection('audit', static function (StoredEvent $event): void {});
    $registry = new ProjectionRegistry([$totals, $audit]);
    $registry->register($totals);

    $registry->get('orders.totals')->handle(projectionRegistryEvent());
    $registry->get('orders.totals')->reset();
    $audit->reset();

    expect($registry->names())->toBe(['audit', 'orders.totals'])
        ->and(array_map(static fn (Projection $p): string => $p->name(), $registry->all()))->toBe(['audit', 'orders.totals'])
        ->and($registry->has('audit'))->toBeTrue()
        ->and($registry->has('nope'))->toBeFalse()
        ->and($totals->handles())->toBe(['order.placed'])
        ->and($audit->handles())->toBe([])
        ->and([$seen, $resets])->toBe([['order.placed'], 1]);
});

it('refuses two projections of one name, an empty name, and a name it does not know', function (): void {
    $registry = new ProjectionRegistry([new FunctionProjection('audit', static function (StoredEvent $event): void {})]);

    expect(fn () => $registry->register(new FunctionProjection('audit', static function (StoredEvent $event): void {})))->toThrow(ConfigurationException::class, 'Two projections are named [audit]')
        ->and(fn () => $registry->register(new FunctionProjection('', static function (StoredEvent $event): void {})))->toThrow(ConfigurationException::class, 'non-empty name')
        ->and(fn () => $registry->get('orders'))->toThrow(EventSourcingException::class, 'No projection is named [orders]; registered: audit.');
});
