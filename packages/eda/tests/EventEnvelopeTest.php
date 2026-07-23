<?php

declare(strict_types=1);

use Firefly\Eda\EventEnvelope;

it('defaults a uuid4 eventId and a timestamp, and exposes its fields', function () {
    $envelope = new EventEnvelope('order.placed', 'firefly.events', ['id' => 7]);

    expect($envelope->eventType)->toBe('order.placed')
        ->and($envelope->destination)->toBe('firefly.events')
        ->and($envelope->payload)->toBe(['id' => 7])
        ->and($envelope->headers)->toBe([])
        ->and($envelope->eventId)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/')
        ->and($envelope->timestamp)->toBeInstanceOf(DateTimeImmutable::class);
});

it('withHeaders merges (new wins) and preserves identity + timestamp', function () {
    $base = new EventEnvelope('order.placed', 'firefly.events', [], ['a' => '1', 'b' => '2']);
    $merged = $base->withHeaders(['b' => 'X', 'c' => '3']);

    expect($merged->headers)->toBe(['a' => '1', 'b' => 'X', 'c' => '3'])
        ->and($merged->eventId)->toBe($base->eventId)
        ->and($merged->timestamp)->toBe($base->timestamp)
        ->and($base->headers)->toBe(['a' => '1', 'b' => '2']); // original untouched
});

it('round-trips through toArray/fromArray', function () {
    $envelope = new EventEnvelope('user.created', 'firefly.events', ['email' => 'x@y.z'], ['x-trace' => 'abc']);
    $restored = EventEnvelope::fromArray($envelope->toArray());

    expect($restored->toArray())->toBe($envelope->toArray())
        ->and($restored->eventId)->toBe($envelope->eventId)
        ->and($restored->timestamp->format(DATE_ATOM))->toBe($envelope->timestamp->format(DATE_ATOM));
});
