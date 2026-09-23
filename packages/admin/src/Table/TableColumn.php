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
