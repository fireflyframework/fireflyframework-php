<?php

declare(strict_types=1);

namespace Firefly\Validation\Rule;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** A strictly-positive monetary amount with at most two fractional digits, e.g. `19.99`. */
final class PositiveMoney implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $normalized = is_int($value) || is_float($value) ? (string) $value : (is_string($value) ? $value : '');
        if (preg_match('/^\d+(\.\d{1,2})?$/', $normalized) !== 1 || (float) $normalized <= 0.0) {
            $fail('The :attribute must be a positive monetary amount with at most two decimals.');
        }
    }
}
