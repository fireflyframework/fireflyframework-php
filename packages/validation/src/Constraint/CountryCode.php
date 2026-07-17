<?php

declare(strict_types=1);

namespace Firefly\Validation\Constraint;

use Attribute;
use Firefly\Validation\Rule\CountryCode as CountryCodeRule;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final class CountryCode implements Constraint
{
    /** @return list<CountryCodeRule> */
    public function toRules(): array
    {
        return [new CountryCodeRule];
    }
}
