<?php

declare(strict_types=1);

namespace Firefly\Validation\Tests\Fixtures\Constraint;

use Firefly\Validation\Constraint\NotBlank;
use Firefly\Validation\Valid;

final class SelfReferential
{
    public function __construct(
        #[NotBlank]
        public readonly string $label,
        #[Valid]
        public readonly ?SelfReferential $parent = null,
    ) {}
}
