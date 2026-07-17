<?php

declare(strict_types=1);

namespace Firefly\Validation\Constraint;

use Attribute;
use Firefly\Validation\Rule\LanguageTag as LanguageTagRule;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final class LanguageTag implements Constraint
{
    /** @return list<LanguageTagRule> */
    public function toRules(): array
    {
        return [new LanguageTagRule];
    }
}
