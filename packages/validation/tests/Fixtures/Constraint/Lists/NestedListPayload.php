<?php

declare(strict_types=1);

namespace Firefly\Validation\Tests\Fixtures\Constraint\Lists;

use Firefly\Validation\Valid;

/** A list of lists is out of scope, and refused rather than half-cascaded. */
final class NestedListPayload
{
    /**
     * @param  list<list<LinePayload>>  $grid
     */
    public function __construct(
        #[Valid]
        public readonly array $grid = [],
    ) {}
}
