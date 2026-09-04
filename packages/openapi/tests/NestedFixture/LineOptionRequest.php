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
     * `$notes` holds a list of SCALARS. Neither element-type path answers for it — `string` is not a class,
     * so RouteScanner leaves the member out of its hydration table and ElementTypes' reflection mirror
     * declines it too — which is exactly why the type expression is read afterwards, by the one step that
     * runs after BOTH paths have declined and so cannot disagree with either.
     *
     * @param  list<string>  $notes
     */
    public function __construct(
        #[NotBlank] public readonly string $code,
        #[Min(0)] public readonly int $surchargeMinor = 0,
        public readonly array $notes = [],
    ) {}
}
