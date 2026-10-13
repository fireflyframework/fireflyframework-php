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
 * would resume into a different execution than the one that ran. So is a cyclic object graph, or one nested
 * deeper than json_decode() reads back, which would otherwise exhaust memory and kill the worker mid-checkpoint.
 */
final class StateSerializer
{
    private const int FLAGS = JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    /**
     * The nesting depth a checkpoint may reach, matching the depth json_decode() reads back by default.
     */
    private const int MAX_DEPTH = 512;

    public static function normalize(mixed $value, string $path = 'value'): mixed
    {
        return self::normalizeAt($value, $path, [], 0);
    }

    /**
     * Normalizes one value, carrying the ids of the objects on the CURRENT path so a cycle (an entity
     * holding its parent, a DTO with a back-reference) is refused with its path instead of recursing until
     * PHP runs out of memory mid-checkpoint. An id leaves the set once its subtree is done, so the same
     * object reached twice through sibling branches is a shared reference, not a cycle, and still works.
     *
     * @param  array<int, true>  $ancestors
     */
    private static function normalizeAt(mixed $value, string $path, array $ancestors, int $depth): mixed
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        if ($depth >= self::MAX_DEPTH) {
            throw new OrchestrationException("{$path} nests deeper than ".self::MAX_DEPTH.' levels, which an orchestration checkpoint cannot persist.', 'ORCHESTRATION_UNSERIALIZABLE');
        }

        if (is_array($value)) {
            $normalized = [];
            foreach ($value as $key => $item) {
                $normalized[$key] = self::normalizeAt($item, $path.'.'.$key, $ancestors, $depth + 1);
            }

            return $normalized;
        }

        if (is_object($value) && ! $value instanceof UnitEnum && ! $value instanceof DateTimeInterface && ! $value instanceof Closure) {
            $id = spl_object_id($value);
            if (isset($ancestors[$id])) {
                throw new OrchestrationException("{$path} holds a cyclic reference to a ".get_debug_type($value).' already on its own path, which an orchestration checkpoint cannot persist. Return acyclic data from the step.', 'ORCHESTRATION_UNSERIALIZABLE');
            }
            $ancestors[$id] = true;
        }

        return match (true) {
            $value instanceof JsonSerializable => self::normalizeAt($value->jsonSerialize(), $path, $ancestors, $depth + 1),
            $value instanceof BackedEnum => $value->value,
            $value instanceof UnitEnum => $value->name,
            $value instanceof DateTimeInterface => DateTimeImmutable::createFromInterface($value)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.uP'),
            $value instanceof Traversable => self::normalizeAt(iterator_to_array($value), $path, $ancestors, $depth + 1),
            $value instanceof Closure => throw new OrchestrationException("{$path} holds a closure, which an orchestration checkpoint cannot persist. Return data (an array, a scalar, a JsonSerializable) from the step.", 'ORCHESTRATION_UNSERIALIZABLE'),
            is_object($value) => self::normalizeAt(get_object_vars($value), $path, $ancestors, $depth + 1),
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
