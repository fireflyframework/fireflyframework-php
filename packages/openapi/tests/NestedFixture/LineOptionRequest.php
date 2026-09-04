<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Tests\NestedFixture;

use Firefly\Validation\Constraint\Min;
use Firefly\Validation\Constraint\NotBlank;

/**
 * The THIRD level of the graph: reached only through `CreateOrderRequest -> lines[] -> options[]`, so it
 * exists to prove that a component is emitted at a depth no single #[Valid] cascade reaches. ConstraintScanner
 * flattens exactly one #[Valid] level and never cascades through an `array` member at all, so every rule on
 * this class reaches the document through its OWN manifest entry or not at all.
 */
final class LineOptionRequest
{
    /**
     * `$notes` holds a list of SCALARS, which is the case both element-type paths deliberately decline to
     * answer: `string` is not a class, so RouteScanner leaves the member out of its hydration table and the
     * generator leaves `items` off rather than emit one only the reflection path could produce.
     *
     * @param  list<string>  $notes
     */
    public function __construct(
        #[NotBlank] public readonly string $code,
        #[Min(0)] public readonly int $surchargeMinor = 0,
        public readonly array $notes = [],
    ) {}
}
