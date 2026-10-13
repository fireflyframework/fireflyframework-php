<?php

declare(strict_types=1);

use Firefly\Eda\JsonSerializer;
use Firefly\EventSourcing\Event\EventEnvelopeCodec;
use Firefly\EventSourcing\Event\EventMetadata;
use Firefly\EventSourcing\Event\NewEvent;

it('stores an event as the eda envelope the bound serializer writes, and reads payload and metadata back', function (): void {
    $codec = new EventEnvelopeCodec(new JsonSerializer);
    $event = new NewEvent('order.placed', ['orderId' => 'o-1', 'total' => 12.5], 2, new EventMetadata('corr-1', tenantId: 'acme'), 'evt-1');

    $raw = $codec->encode($event, 'order');
    /** @var array{eventType: string, destination: string, eventId: string, headers: array<string, string>} $wire */
    $wire = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);

    expect($wire['eventType'])->toBe('order.placed')
        ->and($wire['destination'])->toBe('order')
        ->and($wire['eventId'])->toBe('evt-1')
        ->and($wire['headers'])->toBe(['x-correlation-id' => 'corr-1', 'x-tenant-id' => 'acme'])
        ->and($codec->decode($raw))->toEqual(['payload' => ['orderId' => 'o-1', 'total' => 12.5], 'metadata' => new EventMetadata('corr-1', tenantId: 'acme')]);
});
