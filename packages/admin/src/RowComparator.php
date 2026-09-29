<?php

declare(strict_types=1);

namespace Firefly\Admin;

use Closure;

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
 * A SORT ASKS FOR `forColumn()`, NEVER FOR `compare()` DIRECTLY. Which of the two comparisons a value gets —
 * arithmetic or natural text — is a property of the COLUMN, decided once from everything in it, and not of
 * the pair `usort()` happens to be holding. Deciding it per pair is not merely inelegant, it stops being an
 * ordering at all; `forColumn()` carries the cycle that proves it.
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
     * The ordering for ONE COLUMN: the choice between the two comparisons made ONCE, from everything the
     * column holds, and then applied to every pair of it.
     *
     * THE CHOICE CANNOT BE MADE PER PAIR, and that is the whole reason this method exists rather than every
     * caller reaching for `compare()`. Take a `version` column holding `1.10`, `1.9` and `1.9-beta` — a
     * mixture no schema forbids and no validation catches. Pair by pair: `1.10 < 1.9`, because both are
     * numeric and 1.1 is less than 1.9; `1.9 < 1.9-beta`, because one of them is not numeric and a prefix
     * sorts before what extends it; and `1.9-beta < 1.10`, naturally, because 9 is less than 10. The relation
     * closes a cycle, so it is not a strict weak ordering and `usort()` is entitled to answer anything: those
     * three rows really do come back in three different orders depending on which order the payload arrived
     * in, and the payload is rebuilt per request. That is exactly the "a row is then seen twice, and another
     * never" that `Table\InMemoryListing`'s tiebreak exists to prevent — and the tiebreak cannot rescue it,
     * because a tiebreak runs only where the primary comparison returned 0 and this one returns a confident,
     * inconsistent non-zero.
     *
     * So the column commits before the first comparison: `compare()`'s arithmetic ONLY when every value in it
     * is a number, and otherwise the natural text comparison for every pair. Empties are skipped by the scan
     * rather than counted against it — they are ranked out by `rankEmpty()` before any of this is reached,
     * and where a caller compares them anyway (a tiebreak does) they are the smallest text under either
     * rule, so a nullable column of numbers keeps its arithmetic. `9` still precedes `100` in a column of
     * durations, `Bean2` still precedes `Bean10` in a column of class names, and a column that mixes the two
     * now answers the same way whatever order it was handed in.
     *
     * @param  iterable<mixed>  $values  every value the column holds across the rows about to be ordered
     * @return Closure(mixed, mixed): int
     */
    public static function forColumn(iterable $values): Closure
    {
        foreach ($values as $value) {
            if ($value === null || $value === '' || is_numeric($value)) {
                continue;
            }

            return static fn (mixed $a, mixed $b): int => strnatcasecmp(self::text($a), self::text($b));
        }

        return self::compare(...);
    }

    /**
     * Identity cannot equate distinct spellings merely because their friendly ordering ties.
     *
     * @param  iterable<mixed>  $values
     * @return Closure(mixed, mixed): int
     */
    public static function forIdentity(iterable $values): Closure
    {
        $compare = self::forColumn($values);

        return static fn (mixed $a, mixed $b): int => $compare($a, $b) ?: strcmp(self::text($a), self::text($b));
    }

    /**
     * Order two values as a reader would expect them ordered, emptiness aside.
     *
     * Numbers compare as numbers, so `9` precedes `100` and a column of durations is not sorted by its first
     * digit. The addition rather than a float cast keeps a nineteen-digit identifier exact: past 2^53 two
     * adjacent snowflake ids cast to the same float and would compare equal. Everything else compares
     * naturally and case-insensitively, so `Bean2` precedes `Bean10` and a list of class names does not split
     * into an upper-case half and a lower-case one.
     *
     * THIS IS THE PAIRWISE RULE, and on its own it belongs only where there is no column to be consistent
     * with: the unpaged `gt`/`lt` filter, which holds one cell against one operand the reader typed and asks
     * a yes-or-no question about the two of them. A SORT goes through `forColumn()`, which picks between the
     * two comparisons above once for the whole column — see there for the cycle a per-pair choice opens.
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
