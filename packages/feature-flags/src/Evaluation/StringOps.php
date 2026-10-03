<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Evaluation;

/**
 * flagd's `starts_with` / `ends_with`: exactly two string arguments, otherwise null (so the default variant
 * applies). Bytes compare as Python's code points do: a UTF-8 prefix or suffix of a UTF-8 string ends on a
 * character boundary.
 */
final class StringOps
{
    /**
     * @param  list<mixed>  $args
     */
    public static function startsWith(mixed $data, array $args): ?bool
    {
        return count($args) === 2 && is_string($args[0]) && is_string($args[1]) ? str_starts_with($args[0], $args[1]) : null;
    }

    /**
     * @param  list<mixed>  $args
     */
    public static function endsWith(mixed $data, array $args): ?bool
    {
        return count($args) === 2 && is_string($args[0]) && is_string($args[1]) ? str_ends_with($args[0], $args[1]) : null;
    }
}
