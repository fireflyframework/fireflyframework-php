<?php

declare(strict_types=1);

namespace Firefly\Validation\Constraint;

use Attribute;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The escape hatch: attach any Laravel rule string or ValidationRule object directly, so a rule with no
 * bespoke constraint attribute still works. A variadic ctor param cannot be property-promoted, so the
 * list is assigned in the body.
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final class Rules implements Constraint
{
    /** @var list<string|ValidationRule> */
    public readonly array $rules;

    public function __construct(string|ValidationRule ...$rules)
    {
        $this->rules = array_values($rules);
    }

    public function toRules(): array
    {
        return $this->rules;
    }
}
