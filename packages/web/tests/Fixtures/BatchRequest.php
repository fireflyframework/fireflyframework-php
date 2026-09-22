<?php

declare(strict_types=1);

namespace Firefly\Web\Tests\Fixtures;

use Firefly\Validation\Valid;

/**
 * A list of DTOs declared by #[Valid(each:)] ALONE — no `@param`, no `@var`. The hydrator must build every
 * element as a TransferLine from that attribute, or a body the validator accepted would be refused as a 400.
 */
final class BatchRequest
{
    // @phpstan-ignore missingType.iterableValue (deliberately no docblock: the element class is stated by each: alone)
    public function __construct(
        #[Valid(each: TransferLine::class)]
        public readonly array $lines = [],
    ) {}
}
