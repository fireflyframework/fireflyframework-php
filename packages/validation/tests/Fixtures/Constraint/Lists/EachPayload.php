<?php

declare(strict_types=1);

namespace Firefly\Validation\Tests\Fixtures\Constraint\Lists;

use Firefly\Validation\Valid;

/** No docblock at all: the element class is stated in code, Bean Validation's `List<@Valid Line>`. */
final class EachPayload
{
    // @phpstan-ignore missingType.iterableValue (deliberately no docblock: the element class is stated by each: alone)
    public function __construct(
        #[Valid(each: LinePayload::class)]
        public readonly array $lines = [],
    ) {}
}
