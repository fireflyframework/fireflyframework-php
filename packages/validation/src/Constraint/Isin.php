<?php

declare(strict_types=1);

namespace Firefly\Validation\Constraint;

use Attribute;
use Firefly\Validation\Rule\Isin as IsinRule;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final class Isin implements Constraint
{
    /** @return list<IsinRule> */
    public function toRules(): array
    {
        return [new IsinRule];
    }
}
