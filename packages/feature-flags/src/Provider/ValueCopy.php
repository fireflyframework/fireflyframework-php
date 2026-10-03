<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Provider;

use Generator;
use ReflectionReference;
use SplObjectStorage;
use stdClass;
use UnexpectedValueException;

/**
 * A deep copy of a flag value in Json's faithful form, so what a caller receives shares no instance with the
 * stored definition: every stdClass is rebuilt as a new stdClass (an empty or list-like object stays an object)
 * and every array is rebuilt member by member. Anything else (a scalar, null, a caller's own object) is kept.
 *
 * Each operation preserves stdClass aliases and cycles without retaining the originals. PHP array-valued
 * references are unsupported: unlike decoded JSON they can form recursive or exponentially expanded graphs.
 * They and values deeper than MAX_DEPTH fail explicitly instead of returning a partially shared snapshot.
 * Valid definitions nest at most 256 levels and have no PHP references; no value-count limit is imposed.
 * Equal arrays with identical object members reuse a completed copy through PHP copy-on-write. A shallow
 * signature buckets candidates; strict equality confirms them without invoking foreign-object methods.
 * Foreign objects, including those nested inside arrays/stdClass, are retained by identity.
 *
 * @internal
 */
final class ValueCopy
{
    public const int MAX_DEPTH = 512;

    public static function of(mixed $value): mixed
    {
        /** @var SplObjectStorage<stdClass, stdClass> $objects */
        $objects = new SplObjectStorage;

        $arrays = [];

        return self::copy($value, 1, $objects, $arrays);
    }

    /** Exposure-only budget: containers and scalars count once per occurrence, including repeated references. */
    public static function forExposure(mixed $value): mixed
    {
        $remaining = 10000;
        $stack = [self::members([$value])];
        while ($stack !== []) {
            $iterator = $stack[array_key_last($stack)];
            if (! $iterator->valid()) {
                array_pop($stack);

                continue;
            }
            if ($remaining-- === 0) {
                throw new UnexpectedValueException('Exposure snapshot exceeds 10000 value occurrences');
            }
            $member = $iterator->current();
            $iterator->next();
            if (is_array($member) || $member instanceof stdClass) {
                $stack[] = self::members($member);
            }
        }

        /** @var SplObjectStorage<stdClass, stdClass> $objects */
        $objects = new SplObjectStorage;
        $arrays = [];

        // Native array equality can expand compact graphs again; the bounded snapshot needs no array memo.
        return self::copy($value, 1, $objects, $arrays, false);
    }

    /** @return Generator<int, mixed> */
    private static function members(mixed $value): Generator
    {
        if ($value instanceof stdClass) {
            $value = get_object_vars($value);
        }
        if (is_array($value)) {
            foreach ($value as $member) {
                yield $member;
            }
        }
    }

    /**
     * @param  SplObjectStorage<stdClass, stdClass>  $objects
     * @param  array<string, list<array{array<array-key, mixed>, array<array-key, mixed>}>>  $arrays
     */
    private static function copy(mixed $value, int $depth, SplObjectStorage $objects, array &$arrays, bool $memoizeArrays = true): mixed
    {
        if ($value instanceof stdClass && $objects->offsetExists($value)) {
            return $objects[$value];
        }
        if ($depth > self::MAX_DEPTH) {
            throw new UnexpectedValueException('Flag value exceeds the snapshot depth limit');
        }

        if ($value instanceof stdClass) {
            $copy = new stdClass;
            $objects[$value] = $copy;
            foreach (get_object_vars($value) as $name => $member) {
                $copy->{$name} = self::copy($member, $depth + 1, $objects, $arrays, $memoizeArrays);
            }

            return $copy;
        }

        if (is_array($value)) {
            if ($memoizeArrays) {
                $signature = self::arraySignature($value);
                foreach ($arrays[$signature] ?? [] as [$original, $snapshot]) {
                    if ($original === $value) {
                        return $snapshot;
                    }
                }
            }

            $copy = [];
            foreach ($value as $key => $member) {
                if (is_array($member) && ReflectionReference::fromArrayElement($value, $key) !== null) {
                    throw new UnexpectedValueException('Flag value contains unsupported array references');
                }
                $copy[$key] = self::copy($member, $depth + 1, $objects, $arrays, $memoizeArrays);
            }
            if ($memoizeArrays) {
                $arrays[$signature][] = [$value, $copy];
            }

            return $copy;
        }

        return $value;
    }

    /** @param array<array-key, mixed> $value */
    private static function arraySignature(array $value): string
    {
        $shape = [];
        foreach ($value as $key => $member) {
            if (is_array($member) && ReflectionReference::fromArrayElement($value, $key) !== null) {
                throw new UnexpectedValueException('Flag value contains unsupported array references');
            }
            $shape[] = [$key, get_debug_type($member), match (true) {
                is_array($member) => count($member),
                is_object($member) => spl_object_id($member),
                is_resource($member) => get_resource_id($member),
                default => $member,
            }];
        }

        return hash('sha256', serialize($shape));
    }
}
