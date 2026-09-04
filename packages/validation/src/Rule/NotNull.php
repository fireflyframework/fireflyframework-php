<?php

declare(strict_types=1);

namespace Firefly\Validation\Rule;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The @NotNull semantic Laravel lacks a pure-string rule for: reject ONLY a strict null, while allowing
 * an empty string, 0, and false (which `required` would wrongly reject). Paired with `present` by the
 * #[NotNull] constraint so the field must also be present in the payload.
 *
 * It is NullAware because that is its entire point. Every other constraint now compiles behind Laravel's
 * `nullable` flag so a present-but-null value skips it (Jakarta: only @NotNull rejects null); a rule object
 * is never implicit to Illuminate, so `nullable` would skip this rule too and #[NotNull] would quietly stop
 * rejecting anything. The marker tells the ConstraintScanner to withhold the flag from this property.
 */
final class NotNull implements NullAware, ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null) {
            $fail('The :attribute must not be null.');
        }
    }
}
