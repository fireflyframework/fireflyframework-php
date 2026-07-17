<?php

declare(strict_types=1);

namespace Firefly\Validation\Constraint;

use Attribute;
use Firefly\Validation\Rule\PostalCode as PostalCodeRule;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final class PostalCode implements Constraint
{
    /** @return list<PostalCodeRule> */
    public function toRules(): array
    {
        return [new PostalCodeRule];
    }
}
