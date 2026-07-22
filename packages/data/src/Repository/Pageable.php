<?php

declare(strict_types=1);

namespace Firefly\Data\Repository;

use InvalidArgumentException;

/**
 * A page request: a 1-based page number, a page size, and an optional Sort. `offset()` is the zero-based row
 * offset the Eloquent edge feeds to `skip()`. `unpaged()` models "no limit" (a sentinel size) — `isPaged()`
 * distinguishes it. Pure value object; the page number is validated 1-based so the offset math cannot go negative.
 */
final readonly class Pageable
{
    public function __construct(
        public int $page = 1,
        public int $size = 20,
        public ?Sort $sort = null,
    ) {
        if ($page < 1) {
            throw new InvalidArgumentException('Page number is 1-based and must be >= 1.');
        }

        if ($size < 1) {
            throw new InvalidArgumentException('Page size must be >= 1.');
        }
    }

    public static function of(int $page, int $size, ?Sort $sort = null): self
    {
        return new self($page, $size, $sort);
    }

    public static function unpaged(?Sort $sort = null): self
    {
        return new self(1, PHP_INT_MAX, $sort);
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->size;
    }

    public function next(): self
    {
        return new self($this->page + 1, $this->size, $this->sort);
    }

    public function previous(): self
    {
        return new self(max(1, $this->page - 1), $this->size, $this->sort);
    }

    public function isPaged(): bool
    {
        return $this->size !== PHP_INT_MAX;
    }
}
