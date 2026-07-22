<?php

declare(strict_types=1);

namespace Firefly\Data\Repository;

/**
 * One page of results plus the grand total. `totalPages()` is a ceiling division; `hasNext()`/`hasPrevious()`
 * derive from the 1-based page and total pages; `map()` transforms the items while preserving the paging
 * metadata. Pure value object built at the Eloquent edge (EloquentRepository::findPaged) from a page fetch +
 * a count query.
 *
 * @template T
 */
final readonly class Page
{
    /**
     * @param  list<T>  $items
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $page = 1,
        public int $size = 20,
    ) {}

    public function totalPages(): int
    {
        return $this->size > 0 ? (int) ceil($this->total / $this->size) : 0;
    }

    public function hasNext(): bool
    {
        return $this->page < $this->totalPages();
    }

    public function hasPrevious(): bool
    {
        return $this->page > 1;
    }

    public function numberOfElements(): int
    {
        return count($this->items);
    }

    /**
     * @template U
     *
     * @param  callable(T): U  $mapper
     * @return self<U>
     */
    public function map(callable $mapper): self
    {
        return new self(array_map($mapper, $this->items), $this->total, $this->page, $this->size);
    }
}
