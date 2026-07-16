<?php

declare(strict_types=1);

namespace Firefly\Validation\Constraint;

use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final class Min implements Constraint
{
    public function __construct(public readonly int|float $value) {}

    public function toRules(): array
    {
        return ['numeric', 'gte:'.$this->value];
    }
}
