<?php

declare(strict_types=1);

namespace Firefly\Validation\Tests\Fixtures\Constraint\Lists;

use Firefly\Validation\Constraint\NotBlank;
use Firefly\Validation\Valid;

/** A self-referential list: the ancestor guard must stop the cascade after one level, as it does for objects. */
final class TreePayload
{
    /**
     * @param  list<TreePayload>  $children
     */
    public function __construct(
        #[NotBlank]
        public readonly string $label,
        #[Valid]
        public readonly array $children = [],
    ) {}
}
