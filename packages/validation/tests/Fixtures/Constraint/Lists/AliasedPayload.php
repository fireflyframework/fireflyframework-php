<?php

declare(strict_types=1);

namespace Firefly\Validation\Tests\Fixtures\Constraint\Lists;

use Firefly\Validation\Tests\Fixtures\Constraint\Lists\LinePayload as Line;
use Firefly\Validation\Valid;

/** The `X[]` spelling, written through a `use` alias — resolved from the file's imports, not guessed. */
final class AliasedPayload
{
    /**
     * @param  Line[]  $lines
     */
    public function __construct(
        #[Valid]
        public readonly array $lines = [],
    ) {}
}
