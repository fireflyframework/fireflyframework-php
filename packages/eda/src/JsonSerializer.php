<?php

declare(strict_types=1);

namespace Firefly\Eda;

use DateTimeImmutable;
use Exception;
use Firefly\Eda\Exception\SerializationException;
use JsonException;

/**
 * The default (and only shipped) Serializer: json_encode/json_decode over EventEnvelope::toArray()/fromArray().
 * Uses JSON_THROW_ON_ERROR and re-wraps any JsonException as a fail-loud SerializationException; also rejects a
 * decoded value that is not the envelope shape — not only a bare scalar or a missing key, but a member of the
 * wrong TYPE and a timestamp PHP cannot parse — so deserialize() has exactly one failure type, and the broker
 * adapters can turn it into a poison record instead of letting a TypeError out of a consume callback.
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

        if (! is_array($data)) {
            throw new SerializationException('Decoded JSON is not a valid EventEnvelope shape: expected an object, got '.get_debug_type($data).'.');
        }

        // THE SHAPE IS THE TYPES, NOT THE KEYS. An isset() over the six keys let a body with a string payload,
        // an int eventType or a timestamp in a format PHP cannot parse through to EventEnvelope::fromArray, where
        // it died as a TypeError or a DateMalformedStringException — neither of which any adapter caught, so the
        // worker crashed and was restarted onto the same offset for ever. Those bodies are exactly what a producer
        // in another language writes when the two sides disagree on one member; they are the cross-language case
        // this serializer exists for. Every member is checked here, and every refusal is ONE exception type, so
        // the adapters' poison-record path has a single contract to rely on.
        foreach (['eventType', 'destination', 'eventId'] as $member) {
            if (! isset($data[$member]) || ! is_string($data[$member]) || $data[$member] === '') {
                throw new SerializationException(sprintf(
                    'Decoded JSON is not a valid EventEnvelope shape: %s must be a non-empty string, got %s.',
                    $member,
                    self::describe($data[$member] ?? null),
                ));
            }
        }

        // `{}` and `[]` both decode to an empty PHP array, and both are what an empty object looks like from the
        // other side of a JSON boundary; anything else non-array is refused.
        if (! isset($data['payload']) || ! is_array($data['payload'])) {
            throw new SerializationException('Decoded JSON is not a valid EventEnvelope shape: payload must be an object, got '.self::describe($data['payload'] ?? null).'.');
        }

        if (! isset($data['headers']) || ! is_array($data['headers'])) {
            throw new SerializationException('Decoded JSON is not a valid EventEnvelope shape: headers must be an object of strings, got '.self::describe($data['headers'] ?? null).'.');
        }
        foreach ($data['headers'] as $name => $value) {
            if (! is_string($name) || ! is_string($value)) {
                throw new SerializationException(sprintf(
                    'Decoded JSON is not a valid EventEnvelope shape: headers must be an object of strings, header %s is %s.',
                    var_export($name, true),
                    self::describe($value),
                ));
            }
        }

        if (! isset($data['timestamp']) || ! is_string($data['timestamp'])) {
            throw new SerializationException('Decoded JSON is not a valid EventEnvelope shape: timestamp must be an ISO-8601 string, got '.self::describe($data['timestamp'] ?? null).'.');
        }
        try {
            // PHP 8.3 throws a bare \Exception for an unparseable date string; 8.4 throws DateMalformedStringException,
            // which extends it. Catching Exception covers both without a version check.
            $timestamp = new DateTimeImmutable($data['timestamp']);
        } catch (Exception $e) {
            throw new SerializationException('Decoded JSON is not a valid EventEnvelope shape: timestamp '.var_export($data['timestamp'], true).' is not a date PHP can parse.', $e);
        }

        /** @var array<string, mixed> $payload */
        $payload = $data['payload'];
        /** @var array<string, string> $headers */
        $headers = $data['headers'];

        return new EventEnvelope(
            $data['eventType'],
            $data['destination'],
            $payload,
            $headers,
            $data['eventId'],
            $timestamp,
        );
    }

    /** A short, type-only description for the refusal message: the VALUE never goes into an exception. */
    private static function describe(mixed $value): string
    {
        return is_string($value) && $value === '' ? 'an empty string' : get_debug_type($value);
    }
}
