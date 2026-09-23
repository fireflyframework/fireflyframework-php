<?php

declare(strict_types=1);

namespace Firefly\Admin\Table;

use Firefly\Admin\RowComparator;

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
 * of em-dashes on the first page of a descending sort. Emptiness is therefore decided BEFORE the direction is
 * applied and is never mirrored with it — for the same reason the tiebreak is not. Deciding it inside the
 * value comparison and negating the result for `desc` flips the rule along with the ordering, which is
 * precisely the page of em-dashes the rule exists to prevent.
 *
 * The comparison itself is `Firefly\Admin\RowComparator`'s, shared with the data browser's unpaged path, so
 * one column header cannot mean two different orders on two pages of one dashboard.
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
                if (str_contains(mb_strtolower(RowComparator::text($row[$key] ?? null)), $needle)) {
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
        // ONE COMPARISON PER COLUMN, chosen before the first pair is looked at. A column that mixes numbers
        // with anything else has no consistent answer pair by pair — see RowComparator::forColumn() for the
        // cycle — and usort() then returns whatever the arrival order suggested, which is the unstable page
        // this class's tiebreak exists to prevent. The tiebreak is a column too, and is chosen the same way:
        // an inconsistent tiebreak breaks the whole ordering just as thoroughly as an inconsistent primary.
        $compare = RowComparator::forColumn(self::valuesOf($rows, $column));
        $breakTie = RowComparator::forColumn(self::valuesOf($rows, $tiebreak));

        usort($rows, static function (array $a, array $b) use ($column, $direction, $tiebreak, $compare, $breakTie): int {
            $left = $a[$column] ?? null;
            $right = $b[$column] ?? null;

            // Emptiness OUTSIDE the direction: an em-dash is the absence of a value, not a value that sorts
            // low, so it stays at the end of the listing whichever way the column was asked to run.
            $comparison = RowComparator::rankEmpty($left, $right);

            if ($comparison === 0) {
                $comparison = $compare($left, $right);

                if ($direction === 'desc') {
                    $comparison = -$comparison;
                }
            }

            return $comparison !== 0
                ? $comparison
                : $breakTie($a[$tiebreak] ?? null, $b[$tiebreak] ?? null);
        });

        return $rows;
    }

    /**
     * One column of the listing, missing cells included as the `null` the ordering will see — `array_column()`
     * DROPS a row that lacks the key, and a column is judged numeric or not by what the comparison will
     * actually be handed.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<mixed>
     */
    private static function valuesOf(array $rows, string $key): array
    {
        return array_map(static fn (array $row): mixed => $row[$key] ?? null, $rows);
    }
}
