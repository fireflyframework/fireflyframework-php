<?php

declare(strict_types=1);

namespace Firefly\Validation\Tests\Fixtures\Constraint\Lists;

use Firefly\Validation\Constraint\Max;
use Firefly\Validation\Constraint\NotBlank;
use Firefly\Validation\Constraint\NotNull;
use Firefly\Validation\Constraint\Pattern;
use Firefly\Validation\Constraint\Positive;

/**
 * The element every list fixture holds. #[NotNull] on quantity for the reason the skeleton gives: a rule
 * object (or a non-implicit string rule such as `numeric`) is skipped for an ABSENT key, so without an
 * implicit constraint a line with no quantity would pass validation and die in this constructor.
 */
final class LinePayload
{
    public function __construct(
        #[NotBlank]
        #[Pattern('/^[A-Z0-9][A-Z0-9-]{2,31}$/D')]
        public readonly string $sku,
        #[NotNull]
        #[Positive]
        #[Max(999)]
        public readonly int $quantity,
    ) {}
}
