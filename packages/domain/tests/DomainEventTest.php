<?php

declare(strict_types=1);

use Firefly\Domain\DomainEvent;

final readonly class OrderPlaced extends DomainEvent
{
    public function __construct(public string $orderId)
    {
        parent::__construct();
    }
}

it('generates a uuid-shaped event id and an occurredAt timestamp', function () {
    $event = new OrderPlaced('o-1');

    expect($event->eventId())->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/')
        ->and($event->occurredAt())->toBeInstanceOf(DateTimeImmutable::class);
});

it('derives eventType from the concrete class short name', function () {
    expect((new OrderPlaced('o-1'))->eventType())->toBe('OrderPlaced');
});

it('gives distinct event ids to distinct events', function () {
    expect((new OrderPlaced('o-1'))->eventId())->not->toBe((new OrderPlaced('o-2'))->eventId());
});

it('honours an explicit event id and timestamp', function () {
    $at = new DateTimeImmutable('2026-07-21T00:00:00+00:00');
    $event = new readonly class('abc', $at) extends DomainEvent
    {
        public function __construct(string $eventId, DateTimeImmutable $at)
        {
            parent::__construct($eventId, $at);
        }
    };

    expect($event->eventId())->toBe('abc')->and($event->occurredAt())->toBe($at);
});
