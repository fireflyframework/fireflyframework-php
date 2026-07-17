<?php

declare(strict_types=1);

namespace Firefly\Validation\Constraint;

use Attribute;
use Firefly\Validation\Rule\Luhn as LuhnRule;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final class Luhn implements Constraint
{
    /** @return list<LuhnRule> */
    public function toRules(): array
    {
        return [new LuhnRule];
    }
}
