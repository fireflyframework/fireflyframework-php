<?php

declare(strict_types=1);

namespace Firefly\Validation\Constraint;

use Attribute;
use Firefly\Validation\Rule\Currency;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final class CurrencyCode implements Constraint
{
    /** @return list<Currency> */
    public function toRules(): array
    {
        return [new Currency];
    }
}
