<?php

declare(strict_types=1);

namespace Firefly\Validation\Tests\Fixtures\Constraint\Lists;

use Firefly\Validation\Valid;

/** An `each:` that names a class that does not exist — a typo, refused by name. */
final class MissingEachPayload
{
    // @phpstan-ignore missingType.iterableValue (deliberately no docblock: only the each: name is on trial)
    public function __construct(
        #[Valid(each: 'App\\Missing\\LinePayload')]
        public readonly array $items = [],
    ) {}
}
