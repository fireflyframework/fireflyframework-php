<?php

declare(strict_types=1);

namespace Firefly\Admin\Table;

/**
 * One page of a listing: the rows, the grand total, and the page number that was actually RENDERED.
 *
 * This is Spring Data's `Page`, and the distinction it carries is the one a pager gets wrong. `?page=999`
 * on a nine-page listing is not an error and is not a redirect — it renders page 9 — so the object has to
 * publish the effective page rather than the requested one, or the "Previous" link points at page 998 and
 * the reader is stranded past the end of their own table.
 *
 * The slicing is NOT done here. An actuator payload is sliced in PHP (see InMemoryListing) and a resource
 * is sliced by the database, and both arrive with the page already taken — so the constructor is given the
 * rows and the total and works out everything else from the query.
 *
 * IT IS GENERIC OVER ITS ROW, the way `Page<T>` is. The rows are `list<mixed>` as far as this object is
 * concerned — it never looks inside one — but a caller that hands it a precisely-shaped `list<array{...}>`
 * gets that shape back out of `$rows`, which is what keeps a view (and PHPStan at level max) from having to
 * re-assert the shape of every cell it draws.
 *
 * @template TRow
 */
final readonly class ListingPage
{
    /**
     * @param  list<TRow>  $rows
     */
    private function __construct(
        public array $rows,
        public int $total,
        public int $page,
        public ListingQuery $query,
    ) {}

    /**
     * @template TSliced
     *
     * @param  list<TSliced>  $rows  the page's rows — already sliced, by whatever did the slicing
     * @return self<TSliced>
     */
    public static function sliced(array $rows, int $total, ListingQuery $query): self
    {
        $total = max(0, $total);

        return new self($rows, $total, self::pageFor($total, $query), $query);
    }

    /** The requested page clamped into `[1, last]` — the page a caller must actually slice for. */
    public static function pageFor(int $total, ListingQuery $query): int
    {
        return min(max(1, $query->page), self::lastPageFor($total, $query->size));
    }

    /** Always at least 1: an empty listing has one (empty) page, not zero. */
    public static function lastPageFor(int $total, int $size): int
    {
        return $size > 0 ? max(1, (int) ceil($total / $size)) : 1;
    }

    public function lastPage(): int
    {
        return self::lastPageFor($this->total, $this->query->size);
    }

    public function from(): int
    {
        return $this->total === 0 ? 0 : ($this->page - 1) * $this->query->size + 1;
    }

    public function to(): int
    {
        return min($this->total, $this->page * $this->query->size);
    }

    public function hasPrevious(): bool
    {
        return $this->page > 1;
    }

    public function hasNext(): bool
    {
        return $this->page < $this->lastPage();
    }

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }

    /** Whether the pager's page controls are worth rendering at all. */
    public function isPaged(): bool
    {
        return $this->lastPage() > 1;
    }

    /**
     * A window around the current page. Rendering every page of a four-hundred-page listing is a control
     * nobody can use; the ends are kept by the pager itself, because "first" and "last" are the two jumps
     * people actually make.
     *
     * @return list<int>
     */
    public function window(int $radius = 2): array
    {
        return range(max(1, $this->page - $radius), min($this->lastPage(), $this->page + $radius));
    }

    public function link(int $page): string
    {
        return $this->query->link(['page' => $page]);
    }
}
