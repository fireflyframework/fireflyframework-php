<?php

declare(strict_types=1);

use Firefly\EventSourcing\Event\EventMetadata;
use Firefly\EventSourcing\Event\StoredEvent;
use Firefly\EventSourcing\Exception\UpcastingException;
use Firefly\EventSourcing\Upcasting\CallbackEventUpcaster;
use Firefly\EventSourcing\Upcasting\UpcasterChain;

/** @param array<string, mixed> $payload */
function upcastable(string $type = 'order.placed', int $version = 1, array $payload = ['total' => 10]): StoredEvent
{
    return new StoredEvent('e-1', 'o-1', 'order', 1, 1, $type, $version, $payload, new EventMetadata, new DateTimeImmutable, new DateTimeImmutable);
}

it('walks an event through every version step, renames included, and leaves other events alone', function (): void {
    $chain = new UpcasterChain([
        new CallbackEventUpcaster('order.placed', 1, static fn (array $p): array => [...$p, 'currency' => 'EUR']),
        new CallbackEventUpcaster('order.placed', 2, static fn (array $p): array => ['amount' => $p['total'], 'currency' => $p['currency']], toType: 'order.submitted'),
    ]);

    $upcast = $chain->apply(upcastable());

    expect([$upcast->eventType, $upcast->eventVersion, $upcast->payload])->toBe(['order.submitted', 3, ['amount' => 10, 'currency' => 'EUR']])
        ->and($chain->apply(upcastable('order.paid'))->payload)->toBe(['total' => 10])
        ->and((new UpcasterChain)->isEmpty())->toBeTrue();
});

it('lets the highest priority win among upcasters of the same type and version', function (): void {
    $chain = new UpcasterChain([
        new CallbackEventUpcaster('order.placed', 1, static fn (array $p): array => ['by' => 'low'], priority: 1),
        new CallbackEventUpcaster('order.placed', 1, static fn (array $p): array => ['by' => 'high'], priority: 10),
        new CallbackEventUpcaster('order.placed', 1, static fn (array $p): array => ['by' => 'tie-loses'], priority: 10),
    ]);

    expect($chain->apply(upcastable())->payload)->toBe(['by' => 'high']);
});

it('refuses an upcaster that does not move the event and a chain that never settles', function (): void {
    $still = new UpcasterChain([new CallbackEventUpcaster('order.placed', 1, static fn (array $p): array => $p, toVersion: 1)]);
    $loop = new UpcasterChain([
        new CallbackEventUpcaster('a', 1, static fn (array $p): array => $p, toType: 'b'),
        new CallbackEventUpcaster('b', 2, static fn (array $p): array => $p, toVersion: 1, toType: 'a'),
    ]);

    expect(fn () => $still->apply(upcastable()))->toThrow(UpcastingException::class, 'returned the same type and version')
        ->and(fn () => $loop->apply(upcastable('a')))->toThrow(UpcastingException::class, 'did not settle');
});
