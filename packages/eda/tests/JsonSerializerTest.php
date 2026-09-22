<?php

declare(strict_types=1);

use Firefly\Eda\EventEnvelope;
use Firefly\Eda\Exception\SerializationException;
use Firefly\Eda\JsonSerializer;
use Firefly\Kernel\Exception\Infrastructure\InfrastructureException;

it('round-trips an envelope through JSON', function () {
    $serializer = new JsonSerializer;
    $envelope = new EventEnvelope('user.created', 'firefly.events', ['email' => 'a@b.c'], ['x-trace' => 't1']);

    $restored = $serializer->deserialize($serializer->serialize($envelope));

    expect($restored->eventType)->toBe('user.created')
        ->and($restored->payload)->toBe(['email' => 'a@b.c'])
        ->and($restored->headers)->toBe(['x-trace' => 't1'])
        ->and($restored->eventId)->toBe($envelope->eventId);
});

it('fails loud on malformed JSON', function () {
    (new JsonSerializer)->deserialize('{ this is not json');
})->throws(SerializationException::class);

it('fails loud when the JSON is not an envelope shape', function () {
    (new JsonSerializer)->deserialize('42');
})->throws(SerializationException::class);

it('fails loud when the decoded object is missing required envelope keys', function () {
    (new JsonSerializer)->deserialize(json_encode(['eventType' => 'user.created', 'destination' => 'firefly.events', 'eventId' => 'e1', 'timestamp' => '2024-01-01T00:00:00+00:00'], JSON_THROW_ON_ERROR));
})->throws(SerializationException::class);

it('is a Firefly infrastructure exception', function () {
    expect(new SerializationException('x'))->toBeInstanceOf(InfrastructureException::class);
});

/*
 * EVERY MALFORMED BODY IS A SerializationException, NOT WHATEVER PHP THROWS FIRST. Key presence alone is not a
 * shape: a body with all six keys but a string payload, an int eventType or an unparseable timestamp used to
 * sail past the isset() check into EventEnvelope::fromArray and die there as a TypeError or a
 * DateMalformedStringException — which is exactly the kind of body a producer in another language writes when
 * the two sides disagree on a member's type or a timestamp format. Those escaped the adapters' catch and killed
 * the worker onto the same offset; the serializer is the boundary and answers ONE exception type for ALL of them.
 */
it('refuses a body whose members have the wrong type or an unparseable timestamp as a SerializationException', function (string $raw, string $why) {
    try {
        (new JsonSerializer)->deserialize($raw);
    } catch (SerializationException $e) {
        expect($e->getMessage())->toContain($why);

        return;
    }

    throw new RuntimeException('Expected a SerializationException for: '.$raw);
})->with([
    'payload is a string' => ['{"eventType":"x","destination":"t","payload":"str","headers":{},"eventId":"1","timestamp":"2026-01-01T00:00:00Z"}', 'payload'],
    'payload is a number' => ['{"eventType":"x","destination":"t","payload":7,"headers":{},"eventId":"1","timestamp":"2026-01-01T00:00:00Z"}', 'payload'],
    'headers is a list of numbers' => ['{"eventType":"x","destination":"t","payload":{},"headers":[1,2],"eventId":"1","timestamp":"2026-01-01T00:00:00Z"}', 'headers'],
    'headers is a string' => ['{"eventType":"x","destination":"t","payload":{},"headers":"h","eventId":"1","timestamp":"2026-01-01T00:00:00Z"}', 'headers'],
    'eventType is an int' => ['{"eventType":5,"destination":"t","payload":{},"headers":{},"eventId":"1","timestamp":"2026-01-01T00:00:00Z"}', 'eventType'],
    'eventType is empty' => ['{"eventType":"","destination":"t","payload":{},"headers":{},"eventId":"1","timestamp":"2026-01-01T00:00:00Z"}', 'eventType'],
    'destination is null' => ['{"eventType":"x","destination":null,"payload":{},"headers":{},"eventId":"1","timestamp":"2026-01-01T00:00:00Z"}', 'destination'],
    'eventId is an object' => ['{"eventType":"x","destination":"t","payload":{},"headers":{},"eventId":{"v":1},"timestamp":"2026-01-01T00:00:00Z"}', 'eventId'],
    'timestamp is garbage' => ['{"eventType":"x","destination":"t","payload":{},"headers":{},"eventId":"1","timestamp":"garbage"}', 'timestamp'],
    'timestamp is a number' => ['{"eventType":"x","destination":"t","payload":{},"headers":{},"eventId":"1","timestamp":1700000000}', 'timestamp'],
]);

it('accepts the shapes another language legitimately writes: an empty payload object, an empty headers object, a fractional-second UTC timestamp', function () {
    $envelope = (new JsonSerializer)->deserialize('{"eventType":"order.created","destination":"order.events","payload":{},"headers":{},"eventId":"e1","timestamp":"2026-01-01T00:00:00.123456Z"}');

    expect($envelope->payload)->toBe([])
        ->and($envelope->headers)->toBe([])
        ->and($envelope->timestamp->format('Y-m-d\TH:i:s.uP'))->toBe('2026-01-01T00:00:00.123456+00:00');
});
