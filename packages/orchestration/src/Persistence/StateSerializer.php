<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Persistence;

use BackedEnum;
use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Firefly\Orchestration\Core\Exception\OrchestrationException;
use JsonException;
use JsonSerializable;
use Traversable;
use UnitEnum;

/**
 * Turns what a step returned into what a checkpoint can hold, and checkpoints into JSON and back.
 *
 * EVERY provider stores the NORMALIZED form (spec decision D5), so `memory`, `cache` and `database` behave
 * identically and a resumed execution sees the same values whichever one ran it: JsonSerializable objects
 * become their jsonSerialize(), enums their value (or name), dates a UTC ISO instant, Traversables arrays,
 * and any other object its PUBLIC properties. A closure or a resource cannot be represented at all and is
 * refused with the path it sat at (`stepResults.charge.callback`) — a checkpoint that silently dropped it
 * would resume into a different execution than the one that ran.
 */
final class StateSerializer
{
    private const int FLAGS = JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    public static function normalize(mixed $value, string $path = 'value'): mixed
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        if (is_array($value)) {
            $normalized = [];
            foreach ($value as $key => $item) {
                $normalized[$key] = self::normalize($item, $path.'.'.$key);
            }

            return $normalized;
        }

        return match (true) {
            $value instanceof JsonSerializable => self::normalize($value->jsonSerialize(), $path),
            $value instanceof BackedEnum => $value->value,
            $value instanceof UnitEnum => $value->name,
            $value instanceof DateTimeInterface => DateTimeImmutable::createFromInterface($value)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.uP'),
            $value instanceof Traversable => self::normalize(iterator_to_array($value), $path),
            $value instanceof Closure => throw new OrchestrationException("{$path} holds a closure, which an orchestration checkpoint cannot persist. Return data (an array, a scalar, a JsonSerializable) from the step.", 'ORCHESTRATION_UNSERIALIZABLE'),
            is_object($value) => self::normalize(get_object_vars($value), $path),
            default => throw new OrchestrationException("{$path} holds a ".get_debug_type($value).', which an orchestration checkpoint cannot persist.', 'ORCHESTRATION_UNSERIALIZABLE'),
        };
    }

    public static function encode(ExecutionState $state): string
    {
        return self::encodeArray($state->toArray());
    }

    public static function decode(string $payload): ExecutionState
    {
        return ExecutionState::fromArray(self::decodeArray($payload));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function encodeArray(array $data): string
    {
        return json_encode($data, self::FLAGS);
    }

    /**
     * @return array<string, mixed>
     */
    public static function decodeArray(string $payload): array
    {
        try {
            $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new OrchestrationException("A persisted orchestration payload is not valid JSON: {$error->getMessage()}", 'ORCHESTRATION_CORRUPT_STATE', 500, $error);
        }

        if (! is_array($data)) {
            throw new OrchestrationException('A persisted orchestration payload is not a JSON object.', 'ORCHESTRATION_CORRUPT_STATE');
        }

        $map = [];
        foreach ($data as $key => $value) {
            $map[(string) $key] = $value;
        }

        return $map;
    }
}
