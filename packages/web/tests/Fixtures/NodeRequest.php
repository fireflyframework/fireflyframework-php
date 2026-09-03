<?php

declare(strict_types=1);

namespace Firefly\Web\Tests\Fixtures;

use Firefly\Validation\Constraint\NotBlank;
use Firefly\Validation\Valid;

/**
 * A SELF-REFERENTIAL body DTO. ConstraintScanner deliberately expands #[Valid] exactly one level here (its
 * ancestor guard), but HYDRATION has no such limit: the shape table is keyed by class, so a class that
 * points at itself is one table row and the descent is bounded only by the depth of the payload.
 */
final class NodeRequest
{
    public function __construct(
        #[NotBlank]
        public readonly string $label,
        #[Valid]
        public readonly ?NodeRequest $child = null,
    ) {}
}
