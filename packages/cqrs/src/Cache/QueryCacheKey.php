<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Cache;

use BackedEnum;
use Closure;
use DateTimeInterface;
use JsonException;
use JsonSerializable;
use Stringable;
use UnitEnum;

/**
 * The cache key of a query VALUE OBJECT: sha256 over the query class and a canonical form of its public
 * properties, so two equal queries share an entry and two different ones never do. Reflection-free: public
 * properties are read with get_object_vars() from outside the object's scope, the idiom EdaCommandEventPublisher
 * uses for event payloads.
 *
 * The canonical form sorts associative keys (so property order never matters), keeps list order (so ['x','y'] and
 * ['y','x'] are different queries), writes enums by case, dates as an exact instant with offset, and walks nested
 * objects by their own public properties. Types are kept: 3 and '3' are different keys. Anything that cannot be
 * written stably answers null — the bus then runs the handler uncached.
 *
 * fromProperties() builds the key a value object WOULD get from a property map: it is how #[InvalidatesQuery(with:
 * [...])] evicts one entry without constructing the query.
 */
final class QueryCacheKey
{
    public const int MAX_DEPTH = 8;

    public static function of(object $query): ?string
    {
        return self::fromProperties($query::class, self::publicProperties($query));
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    public static function fromProperties(string $queryClass, array $properties): ?string
    {
        try {
            $canonical = json_encode(
                [$queryClass, self::normalizeArray($properties, 0)],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
            );
        } catch (UnkeyableQueryValue|JsonException) {
            return null;
        }

        return hash('sha256', $canonical);
    }

    /**
     * @return array<string, mixed>
     */
    private static function publicProperties(object $object): array
    {
        $properties = [];
        foreach (get_object_vars($object) as $name => $value) {
            $properties[(string) $name] = $value;
        }

        return $properties;
    }

    private static function normalize(mixed $value, int $depth): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            throw new UnkeyableQueryValue;
        }

        return match (true) {
            $value === null, is_bool($value), is_int($value), is_string($value) => $value,
            is_float($value) => is_finite($value) ? $value : throw new UnkeyableQueryValue,
            is_array($value) => self::normalizeArray($value, $depth + 1),
            $value instanceof BackedEnum => ['enum' => $value::class, 'value' => $value->value],
            $value instanceof UnitEnum => ['enum' => $value::class, 'name' => $value->name],
            $value instanceof DateTimeInterface => ['instant' => $value->format('Y-m-d\TH:i:s.uP')],
            $value instanceof Closure => throw new UnkeyableQueryValue,
            $value instanceof JsonSerializable => ['json' => self::normalize($value->jsonSerialize(), $depth + 1)],
            $value instanceof Stringable => ['string' => (string) $value],
            is_object($value) => ['object' => $value::class, 'properties' => self::normalizeArray(self::publicProperties($value), $depth + 1)],
            default => throw new UnkeyableQueryValue,
        };
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private static function normalizeArray(array $values, int $depth): array
    {
        if (! array_is_list($values)) {
            ksort($values, SORT_STRING);
        }

        $normalized = [];
        foreach ($values as $key => $value) {
            $normalized[$key] = self::normalize($value, $depth);
        }

        return $normalized;
    }
}
