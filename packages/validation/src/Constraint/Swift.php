<?php

declare(strict_types=1);

namespace Firefly\Validation\Constraint;

use Attribute;
use Firefly\Validation\Rule\Swift as SwiftRule;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final class Swift implements Constraint
{
    /** @return list<SwiftRule> */
    public function toRules(): array
    {
        return [new SwiftRule];
    }
}
