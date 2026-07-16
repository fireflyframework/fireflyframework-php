<?php

declare(strict_types=1);

namespace Firefly\Validation\Rule;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** SWIFT code — the ISO 9362 synonym of BIC; identical structure, distinct message for clearer diagnostics. */
final class Swift implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match('/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/D', strtoupper($value)) !== 1) {
            $fail('The :attribute must be a valid SWIFT code.');
        }
    }
}
