<?php

declare(strict_types=1);

namespace Firefly\Admin;

/**
 * ONE ordering predicate for every table this dashboard sorts in PHP.
 *
 * Two surfaces do that sorting. The actuator listings go through `Table\InMemoryListing`, because a payload
 * that arrived as an array from an in-process endpoint has no query planner to push the work into. The data
 * browser goes through `Data\DataQueryEngine`'s unpaged fallback, for a repository that cannot page and must
 * therefore be ordered and sliced after `findAll()`. Different callers, different payloads — and that is
 * exactly why the comparison itself lives in one place. DataQueryEngine already says this about its search
 * filter: "two implementations of one predicate would drift, and the drift would show as the same filter
 * meaning different things on different resources". A sort drifts the same way and is harder to notice: the
 * same gesture, a click on a column header, quietly answers differently on /firefly/beans than on
 * /firefly/data, and nothing fails.
 *
 * EMPTINESS IS A RANK, NOT A VALUE, AND IT IS DECIDED BEFORE THE DIRECTION IS APPLIED. `null` and `''` both
 * render as an em-dash: they are the ABSENCE of an answer rather than an answer that happens to sort low, so
 * they belong at the END of a listing in BOTH directions. Folding that into the value comparison — returning
 * 1 for "left is empty" and then negating the whole result for `desc` — mirrors the emptiness along with the
 * ordering, and a descending sort on a nullable column then opens on a page of em-dashes: the rows the
 * operator asked to see pushed past the fold by the rows that have nothing to show. Callers therefore rank
 * emptiness with `rankEmpty()` FIRST, return that result unnegated, and negate only what `compare()` says
 * about two values that are both present. It is the same reasoning that keeps InMemoryListing's tiebreak
 * ascending under a descending sort: neither emptiness nor identity is a second ordering.
 *
 * The one thing this deliberately does NOT do is decide where empties sit in a FILTER. `greater than` on the
 * unpaged path compares with `compare()` alone, because its paged sibling is `where(col, '>', ?)` in SQL,
 * where the empty string is simply the smallest string; ranking empties last there would make the same filter
 * mean two things depending on whether the repository could page.
 */
final class RowComparator
{
    /**
     * Where two values sit RELATIVE TO EACH OTHER on emptiness alone: 0 when both are empty or neither is,
     * and otherwise the empty one last. Apply this before the direction, never through it — see the class
     * docblock for why a descending sort must not mirror it.
     */
    public static function rankEmpty(mixed $a, mixed $b): int
    {
        return self::emptiness($a) <=> self::emptiness($b);
    }

    /**
     * Order two values as a reader would expect them ordered, emptiness aside.
     *
     * Numbers compare as numbers, so `9` precedes `100` and a column of durations is not sorted by its first
     * digit. The addition rather than a float cast keeps a nineteen-digit identifier exact: past 2^53 two
     * adjacent snowflake ids cast to the same float and would compare equal. Everything else compares
     * naturally and case-insensitively, so `Bean2` precedes `Bean10` and a list of class names does not split
     * into an upper-case half and a lower-case one.
     */
    public static function compare(mixed $a, mixed $b): int
    {
        if (is_numeric($a) && is_numeric($b)) {
            return ($a + 0) <=> ($b + 0);
        }

        return strnatcasecmp(self::text($a), self::text($b));
    }

    /**
     * The text of a cell, for comparing it and for searching inside it.
     *
     * An array is rendered as its JSON rather than dropped, because a listing of, say, a bean's dependencies
     * is an array cell an operator does searches inside; an object falls back to its type, which is the only
     * thing that can be said about it without calling code this class does not own.
     */
    public static function text(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            is_array($value) => (string) json_encode($value, JSON_UNESCAPED_SLASHES),
            default => get_debug_type($value),
        };
    }

    private static function emptiness(mixed $value): int
    {
        return $value === null || $value === '' ? 1 : 0;
    }
}
