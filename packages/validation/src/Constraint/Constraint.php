<?php

declare(strict_types=1);

namespace Firefly\Validation\Constraint;

use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The Bean-Validation (jakarta.validation analog) contract: a metadata attribute that contributes
 * Illuminate validation rules for a SINGLE property. Attribute + Constraint on the same class means the
 * (future) ConstraintScanner finds it via attribute reflection filtered by IS_INSTANCEOF — the same
 * idiom M2 uses for #[Component] stereotypes — so a new constraint attribute is discovered with ZERO
 * scanner change.
 */
interface Constraint
{
    /** @return list<string|ValidationRule> rules merged into the property's rule list (order preserved) */
    public function toRules(): array;
}
