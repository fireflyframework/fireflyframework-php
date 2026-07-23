<?php

declare(strict_types=1);

namespace Firefly\Eda;

use Firefly\Eda\Exception\SerializationException;
use JsonException;

/**
 * The default (and only shipped) Serializer: json_encode/json_decode over EventEnvelope::toArray()/fromArray().
 * Uses JSON_THROW_ON_ERROR and re-wraps any JsonException as a fail-loud SerializationException; also rejects a
 * decoded value that is not the envelope array shape (e.g. a bare scalar).
 */
final class JsonSerializer implements Serializer
{
    public function serialize(EventEnvelope $envelope): string
    {
        try {
            return json_encode($envelope->toArray(), JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new SerializationException('Failed to serialise EventEnvelope to JSON: '.$e->getMessage(), $e);
        }
    }

    public function deserialize(string $raw): EventEnvelope
    {
        try {
            /** @var mixed $data */
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new SerializationException('Failed to deserialise EventEnvelope from JSON: '.$e->getMessage(), $e);
        }

        if (! is_array($data) || ! isset($data['eventType'], $data['destination'], $data['payload'], $data['headers'], $data['eventId'], $data['timestamp'])) {
            throw new SerializationException('Decoded JSON is not a valid EventEnvelope shape.');
        }

        /** @var array{eventType: string, destination: string, payload: array<string, mixed>, headers: array<string, string>, eventId: string, timestamp: string} $data */
        return EventEnvelope::fromArray($data);
    }
}
