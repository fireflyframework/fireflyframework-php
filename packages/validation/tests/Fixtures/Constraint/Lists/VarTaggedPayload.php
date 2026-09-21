<?php

declare(strict_types=1);

namespace Firefly\Validation\Tests\Fixtures\Constraint\Lists;

use Firefly\Validation\Valid;

/** The element class on the member itself: a promoted parameter's doc comment is the property's. */
final class VarTaggedPayload
{
    public function __construct(
        /** @var list<LinePayload> */
        #[Valid]
        public readonly array $lines = [],
    ) {}
}
