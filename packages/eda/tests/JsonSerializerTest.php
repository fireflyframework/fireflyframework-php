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
