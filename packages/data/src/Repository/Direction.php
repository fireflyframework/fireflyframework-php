<?php

declare(strict_types=1);

namespace Firefly\Data\Repository;

/**
 * A sort direction. The backing value IS the Eloquent `orderBy` direction string, so `EloquentRepository`
 * maps an Order to `->orderBy($property, $direction->value)` with no translation table.
 */
enum Direction: string
{
    case Asc = 'asc';
    case Desc = 'desc';
}
