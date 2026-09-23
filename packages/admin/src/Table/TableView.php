<?php

declare(strict_types=1);

namespace Firefly\Admin\Table;

/**
 * An ordered set of columns, and the `<colgroup>` widths computed from them.
 *
 * WHY THE WIDTHS ARE COMPUTED AT ALL, rather than declared per class in the stylesheet. Under
 * `table-layout:fixed` a column with no declared width takes an equal share of whatever is left, which is
 * the behaviour that makes a `Name` column as wide as a fully-qualified class name. Under `table-layout:
 * auto` — which is what every table on this dashboard had, because none of them declared anything — the
 * column widths are derived from the CONTENT, and that is worse: `overflow-wrap:anywhere` contributes its
 * break opportunities to min-content sizing, so a path's minimum width becomes ONE GLYPH and the layout
 * engine cheerfully gives it three characters and hands the slack to the neighbour. Declaring every width
 * is the only way to stop both, and declaring every width means computing the flexible ones.
 *
 * THE ARITHMETIC. Rigid columns are `<n>ch` plus both of their cell paddings, because `box-sizing` is
 * `border-box` on this page: `width:7.5ch` on a padded cell is 7.5 characters INCLUDING the padding, which
 * is about 34px of content and clips the verb `DELETE`. `--row-x` is published on `:root` by the layout
 * from TableSettings, and a custom property inherits into `<col>` like any other, so the `calc()` resolves
 * against whatever density the deployment configured. Flexible columns are a PLAIN PERCENTAGE: this
 * column's weight over the sum of the weights, and nothing else.
 *
 * WHY A BARE PERCENTAGE, AND NOT THE SUBTRACTION IT READS LIKE. The obvious expression is "one hundred
 * percent, minus what the rigid columns took, times this column's share" — and it is the one arrangement
 * that does not reach the browser. A `<col>` width whose `calc()` mixes a percentage with a SUBTRACTED
 * length is not resolvable by the fixed-layout algorithm, and Chromium falls back to `auto`, which is the
 * equal-share behaviour this class exists to prevent. Measured on the Routes colgroup written that way:
 * 453/454/452 on a 1445px table — three identical columns where the weights 5/4/3 asked for 567/454/340.
 * The subtraction is unnecessary anyway, because the fixed-layout algorithm already performs it: it gives
 * every column with a declared length that length, then distributes what REMAINS among the percentage
 * columns in proportion to their percentages. The same colgroup with `41.6667% / 33.3333% / 25%` renders
 * 567/453/340, which is the 5:4:3 split of what the pill column left. Only the ratios between the
 * percentages are ever read, so they never have to sum to anything in particular — and an all-flexible
 * table, where they do sum to 100%, comes out the same way.
 *
 * `ch` IS A MONOSPACE ADVANCE HERE, ON PURPOSE. `ch` resolves against the `<col>`'s own font, which
 * inherits from `<table>` — 13px sans — while the cells these widths are sized for are 12.5px mono. The
 * sheet therefore sets `table.ftable colgroup{font:12.5px var(--mono)}` so that every number in this class
 * means the thing its author was counting: characters of the monospaced text in the cell.
 */
final readonly class TableView
{
    /**
     * @param  list<TableColumn>  $columns
     */
    private function __construct(public array $columns) {}

    public static function of(TableColumn ...$columns): self
    {
        return new self(array_values($columns));
    }

    /**
     * One CSS width expression per column, in order — exactly what the `<colgroup>` emits.
     *
     * @return list<string>
     */
    public function widths(): array
    {
        $weight = 0.0;

        foreach ($this->columns as $column) {
            if (! $column->isRigid()) {
                $weight += $column->width;
            }
        }

        return array_map(
            fn (TableColumn $column): string => $column->isRigid()
                ? 'calc('.self::number($column->width).'ch + 2 * var(--row-x))'
                : self::number($weight > 0.0 ? $column->width / $weight * 100 : 0.0).'%',
            $this->columns,
        );
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_map(static fn (TableColumn $column): string => $column->key, $this->columns);
    }

    /**
     * The keys `?sort=` may name — handed straight to ListingQuery, so a column that is not drawn can never
     * be ordered by and a column that is drawn never needs its key repeating in two places.
     *
     * @return list<string>
     */
    public function sortable(): array
    {
        return array_values(array_map(
            static fn (TableColumn $column): string => $column->key,
            array_filter($this->columns, static fn (TableColumn $column): bool => $column->isSortable()),
        ));
    }

    /** Four decimals, no trailing zeros, always a `.` — a CSS number, never a locale's idea of one. */
    private static function number(float $value): string
    {
        $formatted = rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');

        return $formatted === '' || $formatted === '-' ? '0' : $formatted;
    }
}
