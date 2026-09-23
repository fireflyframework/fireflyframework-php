<?php

declare(strict_types=1);

namespace Firefly\Admin\Table;

/**
 * Search, order and slice a payload that is already in memory.
 *
 * Every page of this dashboard except the data browser reads an actuator endpoint IN-PROCESS and gets back
 * an array — 207 conditions, several hundred beans, the whole flattened environment. There is no query
 * planner to push any of this into, so the work happens here; what changes is WHERE it happens. It used to
 * happen in the browser: the complete list was rendered into every response and a keyup handler hid rows.
 * That is fine for eleven rows, it is a 400KB response and a visibly janky filter at two hundred, and the
 * "12 of 207" readout it produces is a statement about the DOM rather than about the application.
 *
 * THE TIEBREAK IS NOT OPTIONAL. Ordering by a column with duplicate values leaves the rows that tie in
 * whatever order the payload happened to have, and the payload is rebuilt per request — so page 2 can
 * re-order the block that straddles the page boundary, showing a row twice and another never. A second,
 * always-ascending key over something unique (the class, the path, the correlation id) removes the freedom.
 * It stays ascending under a descending sort on purpose: it is an identity, not a second ordering, and
 * mirroring it would make the tie order depend on the direction it was breaking a tie inside.
 *
 * NULL AND EMPTY SORT LAST, in both directions, so a nullable column does not bury its values under a block
 * of em-dashes on the first page of a descending sort.
 */
final class InMemoryListing
{
    /**
     * @template TRow of array<string, mixed>
     *
     * @param  list<TRow>  $rows  the whole listing, keyed by column key
     * @param  list<string>  $searchable  the keys `?q=` looks inside — never every key, because searching a
     *                                    column the page does not show answers a question about a value the
     *                                    reader cannot see
     * @param  string  $tiebreak  a key whose value is unique per row
     * @return ListingPage<TRow>
     */
    public static function page(array $rows, ListingQuery $query, array $searchable, string $tiebreak): ListingPage
    {
        $ordered = self::order(
            self::search($rows, $query->search, $searchable),
            $query->sort ?? $tiebreak,
            $query->direction,
            $tiebreak,
        );

        $total = count($ordered);
        $page = ListingPage::pageFor($total, $query);

        return ListingPage::sliced(
            array_slice($ordered, ($page - 1) * $query->size, $query->size),
            $total,
            $query,
        );
    }

    /**
     * @template TRow of array<string, mixed>
     *
     * @param  list<TRow>  $rows
     * @param  list<string>  $searchable
     * @return list<TRow>
     */
    private static function search(array $rows, ?string $term, array $searchable): array
    {
        if ($term === null || $searchable === []) {
            return $rows;
        }

        $needle = mb_strtolower($term);

        return array_values(array_filter($rows, static function (array $row) use ($needle, $searchable): bool {
            foreach ($searchable as $key) {
                if (str_contains(mb_strtolower(self::text($row[$key] ?? null)), $needle)) {
                    return true;
                }
            }

            return false;
        }));
    }

    /**
     * @template TRow of array<string, mixed>
     *
     * @param  list<TRow>  $rows
     * @return list<TRow>
     */
    private static function order(array $rows, string $column, string $direction, string $tiebreak): array
    {
        usort($rows, static function (array $a, array $b) use ($column, $direction, $tiebreak): int {
            $comparison = self::compare($a[$column] ?? null, $b[$column] ?? null);

            if ($direction === 'desc') {
                $comparison = -$comparison;
            }

            return $comparison !== 0 ? $comparison : self::compare($a[$tiebreak] ?? null, $b[$tiebreak] ?? null);
        });

        return $rows;
    }

    private static function compare(mixed $a, mixed $b): int
    {
        $aEmpty = $a === null || $a === '';
        $bEmpty = $b === null || $b === '';

        if ($aEmpty || $bEmpty) {
            return $aEmpty && $bEmpty ? 0 : ($aEmpty ? 1 : -1);
        }

        if (is_numeric($a) && is_numeric($b)) {
            return (float) $a <=> (float) $b;
        }

        // Natural, case-insensitive: `Bean2` before `Bean10`, and a class list that does not split on case.
        return strnatcasecmp(self::text($a), self::text($b));
    }

    private static function text(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            is_array($value) => (string) json_encode($value, JSON_UNESCAPED_SLASHES),
            default => get_debug_type($value),
        };
    }
}
