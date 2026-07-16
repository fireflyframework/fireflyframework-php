<?php

declare(strict_types=1);

namespace Firefly\Validation\Constraint;

use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final class PositiveOrZero implements Constraint
{
    public function toRules(): array
    {
        return ['numeric', 'gte:0'];
    }
}
