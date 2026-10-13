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
 * deeper than the checkpoint envelope leaves room for, which would otherwise exhaust memory mid-checkpoint or
 * write a row json_decode() cannot read back. So, finally, is a scalar JSON has no form for: a NAN or INF
 * float, or a string (or array key) that is not valid UTF-8 — binary data, a mis-encoded column. Refusing
 * them here, on the normalized value every provider stores, is what keeps `memory` and `cache` from accepting
 * a value the `database` provider would then fail to write.
 */
final class StateSerializer
{
    private const int FLAGS = JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    /**
     * The depth decodeArray() hands json_decode(): a payload of N nested arrays needs N + 1 levels, the
     * innermost scalar counting as one, so at most JSON_DEPTH - 1 arrays read back.
     */
    private const int JSON_DEPTH = 512;

    /**
     * The arrays the checkpoint envelope wraps around every normalized field: the ExecutionState root
     * object. A field (`stepResults`, `variables`, ...) is itself the next array down.
     */
    private const int ENVELOPE_DEPTH = 1;

    /**
     * How many arrays a normalized field may nest, counting the field's own map (`stepResults`) as the
     * first: what json_decode() reads back once the envelope has taken its share, so a value that passes
     * normalize() is a checkpoint that resumes.
     */
    public const int MAX_DEPTH = self::JSON_DEPTH - 1 - self::ENVELOPE_DEPTH;

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
        if (is_float($value) && ! is_finite($value)) {
            $kind = is_nan($value) ? 'NAN' : ($value > 0 ? 'INF' : '-INF');

            throw new OrchestrationException("{$path} holds a non-finite float ({$kind}), which JSON cannot represent and an orchestration checkpoint cannot persist.", 'ORCHESTRATION_UNSERIALIZABLE');
        }

        if (is_string($value) && ! mb_check_encoding($value, 'UTF-8')) {
            throw new OrchestrationException("{$path} holds a string that is not valid UTF-8, which an orchestration checkpoint cannot persist. Encode binary data (base64) before returning it from the step.", 'ORCHESTRATION_UNSERIALIZABLE');
        }

        if ($value === null || is_scalar($value)) {
            return $value;
        }

        if ($depth >= self::MAX_DEPTH) {
            throw new OrchestrationException("{$path} nests deeper than ".self::MAX_DEPTH.' levels, which an orchestration checkpoint cannot persist.', 'ORCHESTRATION_UNSERIALIZABLE');
        }

        if (is_array($value)) {
            $normalized = [];
            foreach ($value as $key => $item) {
                if (is_string($key) && ! mb_check_encoding($key, 'UTF-8')) {
                    throw new OrchestrationException("{$path} holds a key that is not valid UTF-8, which an orchestration checkpoint cannot persist.", 'ORCHESTRATION_UNSERIALIZABLE');
                }
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
     * Encodes a checkpoint. A value normalize() let through always encodes; a field that never went through
     * it (a header, a search attribute) and still holds what JSON cannot represent is refused with the same
     * code rather than escaping as a bare JsonException.
     *
     * @param  array<string, mixed>  $data
     */
    public static function encodeArray(array $data): string
    {
        try {
            return json_encode($data, self::FLAGS, self::JSON_DEPTH);
        } catch (JsonException $error) {
            throw new OrchestrationException("An orchestration checkpoint cannot be encoded as JSON: {$error->getMessage()}", 'ORCHESTRATION_UNSERIALIZABLE', 500, $error);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function decodeArray(string $payload): array
    {
        try {
            $data = json_decode($payload, true, self::JSON_DEPTH, JSON_THROW_ON_ERROR);
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
