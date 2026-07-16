<?php

declare(strict_types=1);

namespace Firefly\Validation\Constraint;

use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final class NotBlank implements Constraint
{
    public function toRules(): array
    {
        // required rejects null/''/[]; string constrains the type; regex:/\S/ requires a non-whitespace
        // char so an all-whitespace string is rejected (JSR-380 @NotBlank's trimmed-length > 0).
        return ['required', 'string', 'regex:/\S/'];
    }
}
