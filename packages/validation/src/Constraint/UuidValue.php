<?php

declare(strict_types=1);

namespace Firefly\Validation\Constraint;

use Attribute;
use Firefly\Validation\Rule\Uuid;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final class UuidValue implements Constraint
{
    /** @return list<Uuid> */
    public function toRules(): array
    {
        return [new Uuid];
    }
}
