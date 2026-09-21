<?php

declare(strict_types=1);

namespace Firefly\Validation\Tests\Fixtures\Constraint\Lists;

use Firefly\Validation\Valid;

/** #[Valid] on a bare `array`: nothing says what it holds, so the cascade is refused, never skipped. */
final class UntypedListPayload
{
    // @phpstan-ignore missingType.iterableValue (deliberately no docblock: an array nothing describes is the case under test)
    public function __construct(
        #[Valid]
        public readonly array $items = [],
    ) {}
}
