<?php

declare(strict_types=1);

namespace Firefly\Validation\Constraint;

use Attribute;
use Firefly\Validation\Rule\Percentage as PercentageRule;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final class Percentage implements Constraint
{
    /** @return list<PercentageRule> */
    public function toRules(): array
    {
        return [new PercentageRule];
    }
}
