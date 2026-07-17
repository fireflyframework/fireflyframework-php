<?php

declare(strict_types=1);

namespace Firefly\Validation\Constraint;

use Attribute;
use Firefly\Validation\Rule\PositiveMoney;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final class Money implements Constraint
{
    /** @return list<PositiveMoney> */
    public function toRules(): array
    {
        return [new PositiveMoney];
    }
}
