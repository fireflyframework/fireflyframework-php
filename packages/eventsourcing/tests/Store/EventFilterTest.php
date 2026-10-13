<?php

declare(strict_types=1);

use Firefly\EventSourcing\Event\EventMetadata;
use Firefly\EventSourcing\Event\StoredEvent;
use Firefly\EventSourcing\Store\EventFilter;
use Firefly\EventSourcing\Store\EventStream;

function filterEvent(string $type, string $aggregateType, string $at, ?string $tenant = null, int $version = 1): StoredEvent
{
    return new StoredEvent('e-'.$type.$version, 'a-1', $aggregateType, $version, $version, $type, 1, [], new EventMetadata(tenantId: $tenant), new DateTimeImmutable($at), new DateTimeImmutable($at));
}

it('matches by event type, aggregate type, half-open time range and tenant', function (): void {
    $placed = filterEvent('order.placed', 'order', '2026-10-13 10:00:00', 'acme');

    expect(EventFilter::ofEventTypes('order.placed')->matches($placed))->toBeTrue()
        ->and(EventFilter::ofEventTypes('order.paid')->matches($placed))->toBeFalse()
        ->and(EventFilter::ofAggregateTypes('order')->matches($placed))->toBeTrue()
        ->and(EventFilter::between(new DateTimeImmutable('2026-10-13 10:00:00'), new DateTimeImmutable('2026-10-13 11:00:00'))->matches($placed))->toBeTrue()
        ->and(EventFilter::between(new DateTimeImmutable('2026-10-13 09:00:00'), new DateTimeImmutable('2026-10-13 10:00:00'))->matches($placed))->toBeFalse()
        ->and((new EventFilter(tenantId: 'acme'))->matches($placed))->toBeTrue()
        ->and((new EventFilter(tenantId: 'globex'))->matches($placed))->toBeFalse();
});

it('reports a stream version as its last event, or 0 when empty', function (): void {
    $stream = new EventStream('a-1', [filterEvent('order.placed', 'order', 'now', version: 1), filterEvent('order.paid', 'order', 'now', version: 2)]);

    expect($stream->version())->toBe(2)
        ->and($stream->aggregateType())->toBe('order')
        ->and($stream->isEmpty())->toBeFalse()
        ->and((new EventStream('a-2', []))->version())->toBe(0)
        ->and((new EventStream('a-2', []))->aggregateType())->toBeNull();
});
