<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Evaluation;

use Firefly\FeatureFlags\Definition\Json;
use stdClass;

/**
 * flagd's `fractional` (fractional-v2), as the reference evaluator runs it (openfeature-flagd-core 1.0.0).
 *
 * The bucket key is the first argument when it evaluated to a string (and nothing else is read), else
 * `$flagd.flagKey` . `targetingKey` from the data; no targeting key (null, "" or any other value Python reads as
 * false) → null, so the default variant applies, and so does an empty bucket key. The hash is MurmurHash3 x86
 * 32-bit of the UTF-8 bytes, seed 0, UNSIGNED — PHP's `murmur3a` digest is exactly mmh3.hash(s, signed=False) —
 * and `bucket = (hash * totalWeight) >> 32`, which stays an int on a 64-bit build because hash < 2^32 and
 * totalWeight <= 2^31 - 1, so the product is below 2^63. A bucket is `[variant]` (weight 1) or
 * `[variant, weight]`; the weight must be an integer (not a bool, not a float) and a negative one counts as 0.
 * A malformed bucket, or weights summing past 2^31 - 1, make the operator null.
 *
 * Where the reference raises rather than answers, this throws a JsonLogicError (GENERAL): reading the bucket key
 * from data that is not an object (`some`, `map`, … hand each item to the operator as its data) or from a
 * `$flagd` that is not one, and joining a flag key and a targeting key that are not both text — except two
 * numbers Python adds to zero, which it reads as no key (null). A PHP `[]` is an empty object here, as
 * everywhere an object is expected. One limit is PHP's: a weight written as an integer beyond 64 bits decodes
 * to a float and is refused, where Python clamps a huge negative one to 0.
 */
final class Fractional
{
    public const int MAX_WEIGHT_SUM = 2147483647;

    /**
     * @param  list<mixed>  $args
     *
     * @throws JsonLogicError where the reference raises
     */
    public static function evaluate(mixed $data, array $args): mixed
    {
        if ($args === []) {
            return null;
        }

        if (is_string($args[0])) {
            $bucketBy = $args[0];
            $args = array_slice($args, 1);
        } else {
            $bucketBy = self::contextBucketKey($data);
        }

        if ($bucketBy === null || $bucketBy === '') {
            return null;
        }

        $fractions = [];
        $total = 0;
        foreach ($args as $arg) {
            if (! Json::isList($arg) || $arg === [] || count($arg) > 2) {
                return null;
            }
            $weight = 1;
            if (count($arg) === 2) {
                if (! is_int($arg[1])) {
                    return null;
                }
                $weight = max(0, $arg[1]);
            }
            // Python sums first and refuses the total after; both give null, and stopping here keeps $total an int.
            if ($weight > self::MAX_WEIGHT_SUM - $total) {
                return null;
            }
            $fractions[] = [$arg[0], $weight];
            $total += $weight;
        }

        $bucket = (self::hash($bucketBy) * $total) >> 32;
        $end = 0;
        foreach ($fractions as [$variant, $weight]) {
            $end += $weight;
            if ($bucket < $end) {
                return $variant;
            }
        }

        return null;
    }

    public static function hash(string $bucketBy): int
    {
        /** @var array{1: int} $unpacked */
        $unpacked = unpack('N', hash('murmur3a', $bucketBy, true));

        return $unpacked[1];
    }

    /**
     * The reference's `data.get("$flagd", {}).get("flagKey", "") + data.get("targetingKey")`, after its
     * `if not targeting_key: return None`.
     *
     * @throws JsonLogicError
     */
    private static function contextBucketKey(mixed $data): ?string
    {
        if (! self::isObject($data)) {
            throw new JsonLogicError(sprintf('fractional reads its bucket key from the data, which is %s, not an object.', get_debug_type($data)));
        }

        $context = Json::members($data);
        $flagd = array_key_exists('$flagd', $context) ? $context['$flagd'] : [];
        if (! self::isObject($flagd)) {
            throw new JsonLogicError(sprintf('fractional reads $flagd.flagKey from an object, not %s.', get_debug_type($flagd)));
        }

        $flagdMembers = Json::members($flagd);
        $seed = array_key_exists('flagKey', $flagdMembers) ? $flagdMembers['flagKey'] : '';
        $targetingKey = $context['targetingKey'] ?? null;

        if (! self::pythonTruthy($targetingKey)) {
            return null;
        }

        if (is_string($seed) && is_string($targetingKey)) {
            return $seed.$targetingKey;
        }

        // Python adds two numbers instead of joining them; a zero sum is falsy (null), any other fails in mmh3.
        $left = self::pythonNumber($seed);
        $right = self::pythonNumber($targetingKey);
        if ($left !== null && $right !== null) {
            $sum = $left + $right;
            if ($sum === 0 || $sum === 0.0) {
                return null;
            }
        }

        throw new JsonLogicError(sprintf(
            'fractional cannot join a flag key (%s) and a targeting key (%s) into a bucket key.',
            get_debug_type($seed),
            get_debug_type($targetingKey),
        ));
    }

    /** A stdClass, a non-list array, or `[]` (an empty object, as everywhere an object is expected). */
    private static function isObject(mixed $value): bool
    {
        return $value instanceof stdClass || (is_array($value) && ($value === [] || ! array_is_list($value)));
    }

    /** Python's `bool()`: an empty container is false, NaN is true. */
    private static function pythonTruthy(mixed $value): bool
    {
        return match (true) {
            $value === null => false,
            is_bool($value) => $value,
            is_int($value) => $value !== 0,
            is_float($value) => $value !== 0.0,
            is_string($value) => $value !== '',
            is_array($value) => $value !== [],
            $value instanceof stdClass => get_object_vars($value) !== [],
            default => true,
        };
    }

    /** The number Python sees (a bool is an int there), or null for anything else. */
    private static function pythonNumber(mixed $value): int|float|null
    {
        return match (true) {
            is_bool($value) => (int) $value,
            is_int($value), is_float($value) => $value,
            default => null,
        };
    }
}
