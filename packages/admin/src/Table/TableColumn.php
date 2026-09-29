<?php

declare(strict_types=1);

namespace Firefly\Admin\Table;

/**
 * One column of a listing: what it is called, what kind of thing it holds, how much room it gets, and
 * whether the reader may order by it.
 *
 * The named constructors carry the DEFAULT width for each kind, and those numbers are the accumulated
 * answer to "how wide is this really": 7.5 characters is `DELETE` in the pill font with room to breathe,
 * 19 is an ISO instant, 9 is a five-figure count with its thousands separator. A caller overrides one when
 * its page knows better — the OAuth2 page's `Active` column is single digits — and never has to invent a
 * mechanism to do it.
 *
 * `$width` means two different things by design, and `isRigid()` says which: for a rigid kind it is a count
 * of CHARACTERS, and for a flexible kind it is a WEIGHT, a relative share of what the rigid columns left.
 * Two units in one property would be a smell if the kind did not decide it; the kind does, TableView reads
 * it through `isRigid()`, and nothing else ever touches it.
 */
final readonly class TableColumn
{
    /**
     * WHAT ONE CHARACTER OF A HEADER COSTS, in the `ch` this class counts every other width in.
     *
     * None of the named constructors below needs this number, because each of their widths was tuned
     * against a label an author wrote: somebody who types `Level` and `11` has already looked at both. A
     * listing whose labels are DERIVED does need it. The data browser humanises a database column name —
     * `failed_login_attempts` draws `Failed login attempts` — and nobody chose that column's name for its
     * length, so nobody can have tuned a width to it.
     *
     * THE TWO FONTS ARE NOT THE SAME FONT, which is the whole reason this is a conversion and not a count.
     * A `<col>`'s `ch` is the 12.5px monospace advance the sheet declares on `table.ftable colgroup`
     * (7.52px in Chromium), while `thead th` is 10px/700 uppercase in the UI face with `.12em` tracking.
     * Measured across the labels a schema actually produces — `Id` 0.88, `Unit price` 0.95,
     * `Failed login attempts` 0.99, `Created at` 1.02, `Warehouse manager` 1.12, `Customer` 1.14,
     * `Amount` 1.20 — one header character costs between 0.88 and 1.20 of those `ch`, the top of the range
     * being the short, round-lettered words rather than the long labels. 1.25 carries every one of them
     * with margin.
     *
     * IT IS AN ESTIMATE, AND THE HEADER THAT USES IT SAYS SO. PHP cannot measure a font, so a pathological
     * label — twelve `W`s measures 1.52 — still overflows its column; the data browser therefore puts the
     * full label on the `<th>`'s `title`, exactly as `_cell` already does for a value it clips.
     */
    public const float HEADER_CH_PER_CHARACTER = 1.25;

    /**
     * What the ordering indicator adds to a SORTABLE header, in the same `ch`.
     *
     * `th .ord` is a 10px-wide inline-block inside an `inline-flex` anchor with `gap:3px`, and that width
     * is declared rather than taken from the arrow — so the 13px is paid whether the column is the sorted
     * one or not, which is what stops a header changing width under the click that sorts it. 13px over a
     * 7.52px `ch` is 1.73, rounded up.
     */
    public const float SORT_INDICATOR_CH = 1.75;

    private function __construct(
        public string $key,
        public string $label,
        public ColumnKind $kind,
        public float $width,
        public bool $sortable,
        public string $separator,
    ) {}

    /** A chip. 7.5 characters is `DELETE` with room — the width the Routes page was clipping. */
    public static function pill(string $key, string $label, float $ch = 7.5, bool $sortable = true): self
    {
        return new self($key, $label, ColumnKind::Pill, $ch, $sortable, '');
    }

    public static function number(string $key, string $label, float $ch = 9, bool $sortable = true): self
    {
        return new self($key, $label, ColumnKind::Number, $ch, $sortable, '');
    }

    /** 19 characters is `2026-09-22 20:49:26`; an age reads shorter and is padded by the same rule. */
    public static function stamp(string $key, string $label, float $ch = 19, bool $sortable = true): self
    {
        return new self($key, $label, ColumnKind::Stamp, $ch, $sortable, '');
    }

    public static function meter(string $label, float $ch = 16): self
    {
        return new self('', $label, ColumnKind::Meter, $ch, false, '');
    }

    public static function actions(string $label = '', float $ch = 11): self
    {
        return new self('', $label, ColumnKind::Actions, $ch, false, '');
    }

    public static function token(string $key, string $label, float $weight = 2, bool $sortable = true): self
    {
        return new self($key, $label, ColumnKind::Token, $weight, $sortable, '');
    }

    public static function path(string $key, string $label, float $weight = 5, bool $sortable = true): self
    {
        return new self($key, $label, ColumnKind::Path, $weight, $sortable, '');
    }

    /**
     * @param  string  $separator  what the name is qualified by — `\` for a class, `.` for a config key or a
     *                             meter name, and `''` when the two lines come from two different fields
     *                             rather than from splitting one (the OAuth2 page's client id over client
     *                             name), in which case the view supplies both halves itself
     */
    public static function qualified(string $key, string $label, float $weight = 4, string $separator = '\\', bool $sortable = true): self
    {
        return new self($key, $label, ColumnKind::Qualified, $weight, $sortable, $separator);
    }

    public static function text(string $key, string $label, float $weight = 4, bool $sortable = false): self
    {
        return new self($key, $label, ColumnKind::Text, $weight, $sortable, '');
    }

    public static function line(string $key, string $label, float $weight = 4, bool $sortable = true): self
    {
        return new self($key, $label, ColumnKind::Line, $weight, $sortable, '');
    }

    /**
     * The same column, widened until its own HEADER fits beside its values.
     *
     * A rigid width is sized from the alphabet the CELLS can hold — nineteen characters for an ISO instant,
     * seven and a half for the verb `DELETE` — and on a listing with hand-written labels that is the whole
     * story, because the author picked the label against the width. It stops being the whole story the
     * moment the label is derived. `thead th` is `white-space:nowrap` and `table.ftable td,th` is
     * `overflow:hidden`, so a header wider than its column is cut mid-glyph; and under the
     * `table-layout:fixed` this system runs on, the column cannot grow to rescue it the way the
     * `table-layout:auto` the data browser used to run on did. So the width becomes the greater of the two
     * needs, never the lesser — a column still holds its values, and now it also says what they are.
     *
     * A FLEXIBLE COLUMN IS RETURNED UNTOUCHED, because its `$width` is a weight and not a character count.
     * Widening it would not widen a column; it would enlarge that column's share of the leftovers at its
     * neighbours' expense, for a reason that has nothing to do with them — and a percentage column on a
     * table this wide clears any header it is likely to be given anyway.
     */
    public function fittingItsHeader(): self
    {
        if (! $this->isRigid()) {
            return $this;
        }

        $header = mb_strlen($this->label) * self::HEADER_CH_PER_CHARACTER
            + ($this->isSortable() ? self::SORT_INDICATOR_CH : 0.0);

        return $header <= $this->width
            ? $this
            : new self($this->key, $this->label, $this->kind, $header, $this->sortable, $this->separator);
    }

    public function cssClass(): string
    {
        return $this->kind->cssClass();
    }

    public function isRigid(): bool
    {
        return $this->kind->isRigid();
    }

    /** Sortable needs a key to sort BY and a kind that can be ordered — both, not either. */
    public function isSortable(): bool
    {
        return $this->sortable && $this->key !== '' && $this->kind->isOrderable();
    }
}
