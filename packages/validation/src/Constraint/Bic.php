<?php

declare(strict_types=1);

namespace Firefly\Validation\Constraint;

use Attribute;
use Firefly\Validation\Rule\Bic as BicRule;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final class Bic implements Constraint
{
    /** @return list<BicRule> */
    public function toRules(): array
    {
        return [new BicRule];
    }
}
