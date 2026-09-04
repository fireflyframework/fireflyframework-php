<?php

declare(strict_types=1);

namespace Firefly\Admin\Tests\Data\Fixtures;

/**
 * An entity that names its key nothing the identifier preference order recognises, so the browser refuses to
 * address a single row of it rather than improvising a WHERE clause.
 */
final class Pair
{
    public function __construct(public string $left, public string $right) {}
}
