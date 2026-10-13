<?php

declare(strict_types=1);

use Firefly\EventSourcing\Event\EventMapper;
use Firefly\EventSourcing\Event\EventMetadata;
use Firefly\EventSourcing\Event\EventTypeRegistry;
use Firefly\EventSourcing\Event\StoredEvent;
use Firefly\EventSourcing\Exception\EventSerializationException;
use Firefly\EventSourcing\Exception\UnknownEventTypeException;
use Firefly\EventSourcing\Scanner\EventSourcingScanner;
use Firefly\EventSourcing\Tests\Fixtures\Events\OrderChannel;
use Firefly\EventSourcing\Tests\Fixtures\Events\OrderPaid;
use Firefly\EventSourcing\Tests\Fixtures\Events\OrderPlaced;
use Firefly\EventSourcing\Tests\Fixtures\Events\Untyped;
use Firefly\EventSourcing\Tests\Fixtures\Moods\Mood;
use Firefly\EventSourcing\Tests\Fixtures\Moods\MoodChanged;

function eventMapper(): EventMapper
{
    return new EventMapper(new EventTypeRegistry((new EventSourcingScanner)->scan(['Firefly\\EventSourcing\\Tests\\Fixtures\\Events\\' => dirname(__DIR__).'/Fixtures/Events'])->eventTypes()));
}

/** @param array<string, mixed> $payload */
function storedAs(string $type, int $version, array $payload): StoredEvent
{
    return new StoredEvent('evt-1', 'o-1', 'order', 1, 1, $type, $version, $payload, new EventMetadata, new DateTimeImmutable('2026-10-13 10:00:00', new DateTimeZone('UTC')), new DateTimeImmutable);
}

it('maps an event to its registered type, version and normalized public properties', function (): void {
    $event = new OrderPlaced('o-1', 12.0, OrderChannel::Phone, new DateTimeImmutable('2026-10-20 00:00:00', new DateTimeZone('UTC')), ['A']);
    $new = eventMapper()->toNewEvent($event, new EventMetadata('corr-1'));

    expect([$new->eventType, $new->eventVersion, $new->eventId, $new->metadata->correlationId])->toBe(['order.placed', 2, $event->eventId, 'corr-1'])
        ->and($new->payload)->toBe(['orderId' => 'o-1', 'total' => 12.0, 'channel' => 'phone', 'promisedBy' => '2026-10-20T00:00:00.000000+00:00', 'skus' => ['A']])
        ->and(eventMapper()->toNewEvent(new OrderPaid('o-1', 1250))->payload)->toBe(['order' => 'o-1', 'amount' => 1250]);
});

it('builds the event back from a JSON-decoded payload, coercing by the compiled parameter types', function (): void {
    // JSON gave 12.0 back as 12; the compiled type says float.
    $event = eventMapper()->toDomainEvent(storedAs('order.placed', 2, ['orderId' => 'o-1', 'total' => 12, 'channel' => 'phone', 'promisedBy' => '2026-10-20T00:00:00.000000+00:00']));

    expect($event)->toBeInstanceOf(OrderPlaced::class)
        ->and($event instanceof OrderPlaced ? [$event->total, $event->channel, $event->promisedBy?->format('Y-m-d'), $event->skus, $event->eventId] : null)->toBe([12.0, OrderChannel::Phone, '2026-10-20', [], 'evt-1'])
        ->and($event->occurredAt->format('Y-m-d H:i'))->toBe('2026-10-13 10:00')
        ->and(eventMapper()->toDomainEvent(storedAs('order.paid', 1, ['order' => 'o-2', 'amount' => 5])))->toEqual(new OrderPaid('o-2', 5, 'evt-1', new DateTimeImmutable('2026-10-13 10:00:00', new DateTimeZone('UTC'))));
});

it('refuses an unregistered class, a stored version without its upcaster, and a payload missing a required value', function (): void {
    expect(fn () => eventMapper()->toNewEvent(new Untyped))->toThrow(UnknownEventTypeException::class, Untyped::class)
        ->and(fn () => eventMapper()->toDomainEvent(storedAs('order.placed', 1, ['orderId' => 'o-1', 'total' => 1])))->toThrow(UnknownEventTypeException::class, 'version 1 after upcasting')
        ->and(fn () => eventMapper()->toDomainEvent(storedAs('order.placed', 2, ['orderId' => 'o-1'])))->toThrow(EventSerializationException::class, 'order.placed.total');
});

it('round-trips a pure enum through its case name, and refuses a stored name that is not a case', function (): void {
    $mapper = new EventMapper(new EventTypeRegistry((new EventSourcingScanner)->scan(['Firefly\\EventSourcing\\Tests\\Fixtures\\Moods\\' => dirname(__DIR__).'/Fixtures/Moods'])->eventTypes()));
    $new = $mapper->toNewEvent(new MoodChanged(Mood::Happy, Mood::Grumpy));
    $stored = storedAs('mood.changed', 1, $new->payload);
    $event = $mapper->toDomainEvent($stored);

    expect($new->payload)->toBe(['mood' => 'Happy', 'previous' => 'Grumpy'])
        ->and($event)->toEqual(new MoodChanged(Mood::Happy, Mood::Grumpy, 'evt-1', $stored->occurredAt))
        ->and($mapper->toDomainEvent(storedAs('mood.changed', 1, ['mood' => 'Grumpy', 'previous' => null])))->toEqual(new MoodChanged(Mood::Grumpy, null, 'evt-1', $stored->occurredAt))
        ->and(fn () => $mapper->toDomainEvent(storedAs('mood.changed', 1, ['mood' => 'Sleepy'])))->toThrow(EventSerializationException::class, 'mood.changed.mood');
});
