<?php

declare(strict_types=1);

namespace Firefly\Validation\Tests\Fixtures\Constraint\Lists;

use Firefly\Validation\Valid;

/** A list of scalars has nothing to cascade into; #[Valid] on it is a mistake the scanner names. */
final class ScalarListPayload
{
    /**
     * @param  list<string>  $tags
     */
    public function __construct(
        #[Valid]
        public readonly array $tags = [],
    ) {}
}
