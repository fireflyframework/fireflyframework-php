<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Provider;

use stdClass;

/**
 * A deep copy of a flag value in Json's faithful form, so what a caller receives shares no instance with the
 * stored definition: every stdClass is rebuilt as a new stdClass (an empty or list-like object stays an object)
 * and every array is rebuilt member by member. Anything else (a scalar, null, a caller's own object) is kept.
 *
 * The copy stops at MAX_DEPTH levels and shares what lies beyond. No valid definition nests that deep
 * (FlagDefinitions::MAX_DEPTH refuses more than 256 levels, and Json decodes at most 512), so a flag's value is
 * always copied whole; the bound only keeps a caller's self-containing default from recursing without end.
 *
 * @internal
 */
final class ValueCopy
{
    public const int MAX_DEPTH = 512;

    public static function of(mixed $value): mixed
    {
        return self::copy($value, 1);
    }

    private static function copy(mixed $value, int $depth): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            return $value;
        }

        if ($value instanceof stdClass) {
            $copy = new stdClass;
            foreach (get_object_vars($value) as $name => $member) {
                $copy->{$name} = self::copy($member, $depth + 1);
            }

            return $copy;
        }

        if (is_array($value)) {
            $copy = [];
            foreach ($value as $key => $member) {
                $copy[$key] = self::copy($member, $depth + 1);
            }

            return $copy;
        }

        return $value;
    }
}
