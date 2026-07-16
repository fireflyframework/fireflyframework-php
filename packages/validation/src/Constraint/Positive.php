<?php

declare(strict_types=1);

namespace Firefly\Validation\Constraint;

use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final class Positive implements Constraint
{
    public function toRules(): array
    {
        return ['numeric', 'gt:0'];
    }
}
