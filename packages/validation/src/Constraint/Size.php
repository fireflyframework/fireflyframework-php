<?php

declare(strict_types=1);

namespace Firefly\Validation\Constraint;

use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final class Size implements Constraint
{
    public function __construct(
        public readonly ?int $min = null,
        public readonly ?int $max = null,
    ) {}

    public function toRules(): array
    {
        if ($this->min !== null && $this->max !== null) {
            return ['between:'.$this->min.','.$this->max];
        }

        if ($this->min !== null) {
            return ['min:'.$this->min];
        }

        if ($this->max !== null) {
            return ['max:'.$this->max];
        }

        return [];
    }
}
