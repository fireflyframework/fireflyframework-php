<?php

declare(strict_types=1);

namespace Firefly\Validation\Constraint;

use Attribute;
use Firefly\Validation\Rule\NotNull as NotNullRule;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final class NotNull implements Constraint
{
    public function toRules(): array
    {
        return ['present', new NotNullRule];
    }
}
