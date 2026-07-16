<?php

declare(strict_types=1);

namespace Firefly\Validation\Rule;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The @NotNull semantic Laravel lacks a pure-string rule for: reject ONLY a strict null, while allowing
 * an empty string, 0, and false (which `required` would wrongly reject). Paired with `present` by the
 * #[NotNull] constraint so the field must also be present in the payload.
 */
final class NotNull implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null) {
            $fail('The :attribute must not be null.');
        }
    }
}
