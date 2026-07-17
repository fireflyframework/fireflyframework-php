<?php

declare(strict_types=1);

namespace Firefly\Validation\Constraint;

use Attribute;
use Firefly\Validation\Rule\DecimalScale as DecimalScaleRule;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final class DecimalScale implements Constraint
{
    public function __construct(public readonly int $scale) {}

    /** @return list<DecimalScaleRule> */
    public function toRules(): array
    {
        return [new DecimalScaleRule($this->scale)];
    }
}
