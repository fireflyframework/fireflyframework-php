<?php

declare(strict_types=1);

namespace Firefly\Validation\Constraint;

use Attribute;
use Firefly\Validation\Rule\Cusip as CusipRule;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final class Cusip implements Constraint
{
    /** @return list<CusipRule> */
    public function toRules(): array
    {
        return [new CusipRule];
    }
}
