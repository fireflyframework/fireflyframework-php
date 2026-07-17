<?php

declare(strict_types=1);

namespace Firefly\Validation\Constraint;

use Attribute;
use Firefly\Validation\Rule\Iban as IbanRule;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final class Iban implements Constraint
{
    /** @return list<IbanRule> */
    public function toRules(): array
    {
        return [new IbanRule];
    }
}
