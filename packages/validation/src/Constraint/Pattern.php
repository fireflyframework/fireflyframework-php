<?php

declare(strict_types=1);

namespace Firefly\Validation\Constraint;

use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final class Pattern implements Constraint
{
    /** @param string $regex a full PCRE pattern WITH delimiters, e.g. '/^[A-Z]+$/D' */
    public function __construct(public readonly string $regex) {}

    public function toRules(): array
    {
        return ['regex:'.$this->regex];
    }
}
