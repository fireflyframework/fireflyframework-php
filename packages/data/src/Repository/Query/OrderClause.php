<?php

declare(strict_types=1);

namespace Firefly\Data\Repository\Query;

/**
 * One parsed ordering from a derived query's OrderBy clause: the snake_case column and 'asc'/'desc'.
 */
final readonly class OrderClause
{
    /**
     * @param  'asc'|'desc'  $dir
     */
    public function __construct(
        public string $field,
        public string $dir,
    ) {}

    /**
     * @return array{field: string, dir: 'asc'|'desc'}
     */
    public function toArray(): array
    {
        return ['field' => $this->field, 'dir' => $this->dir];
    }
}
