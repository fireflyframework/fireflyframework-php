<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Event;

use BackedEnum;
use Closure;
use DateTimeInterface;
use Firefly\EventSourcing\Exception\EventSerializationException;
use JsonSerializable;
use UnitEnum;

/**
 * Turns an event's properties into what JSON holds, and refuses what it cannot. Dates become
 * `Y-m-d\TH:i:s.uP` strings, a backed enum its value, a pure enum its name, a JsonSerializable its
 * jsonSerialize(). A closure, a resource, any other object, a non-finite float and a string that is not UTF-8
 * are refused, with the path they sat at, BEFORE anything is written: an event store must never hold an event it
 * cannot give back.
 */
final class PayloadNormalizer
{
    public const string DATE_FORMAT = 'Y-m-d\TH:i:s.uP';

    public static function normalize(mixed $value, string $path = 'payload'): mixed
    {
        return match (true) {
            $value === null, is_bool($value), is_int($value) => $value,
            is_float($value) => is_finite($value) ? $value : throw EventSerializationException::at($path, 'holds a non-finite float, which JSON cannot represent.'),
            is_string($value) => mb_check_encoding($value, 'UTF-8') ? $value : throw EventSerializationException::at($path, 'holds a string that is not valid UTF-8; encode binary data (base64) first.'),
            is_array($value) => self::normalizeArray($value, $path),
            $value instanceof DateTimeInterface => $value->format(self::DATE_FORMAT),
            $value instanceof BackedEnum => $value->value,
            $value instanceof UnitEnum => $value->name,
            $value instanceof Closure => throw EventSerializationException::at($path, 'holds a closure, which an event store cannot persist.'),
            $value instanceof JsonSerializable => self::normalize($value->jsonSerialize(), $path),
            is_object($value) => throw EventSerializationException::at($path, 'holds an object of class '.$value::class.'; make it JsonSerializable or implement SerializableEvent on the event.'),
            default => throw EventSerializationException::at($path, 'holds a '.get_debug_type($value).', which an event store cannot persist.'),
        };
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private static function normalizeArray(array $values, string $path): array
    {
        $normalized = [];
        foreach ($values as $key => $item) {
            $normalized[$key] = self::normalize($item, $path.'.'.$key);
        }

        return $normalized;
    }
}
