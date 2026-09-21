<?php

declare(strict_types=1);

namespace Firefly\Data\Repository;

/**
 * A page without a total — Spring Data's Slice. Built by over-fetching ONE row past the page size: if it came
 * back there is a next page, and no count query ever runs, which on a large table is the difference between
 * one cheap query and one cheap query plus one expensive one. Property names follow Page (`items`, `page`,
 * `size`); Spring's `content`/`number` are the same things.
 *
 * @template T
 */
final readonly class Slice
{
    /**
     * @param  list<T>  $items
     */
    public function __construct(
        public array $items,
        public bool $hasNext,
        public int $page = 1,
        public int $size = 20,
    ) {}

    public function hasNext(): bool
    {
        return $this->hasNext;
    }

    public function hasPrevious(): bool
    {
        return $this->page > 1;
    }

    public function numberOfElements(): int
    {
        return count($this->items);
    }

    public function nextPageable(): Pageable
    {
        return Pageable::of($this->page + 1, $this->size);
    }

    /**
     * @template U
     *
     * @param  callable(T): U  $mapper
     * @return self<U>
     */
    public function map(callable $mapper): self
    {
        return new self(array_map($mapper, $this->items), $this->hasNext, $this->page, $this->size);
    }
}
