<?php

declare(strict_types=1);

namespace Firefly\Validation\Rule;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** ISO 9362 BIC: 4-letter bank + 2-letter country + 2-char location, optional 3-char branch (length 8 or 11). */
final class Bic implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match('/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/D', strtoupper($value)) !== 1) {
            $fail('The :attribute must be a valid BIC code.');
        }
    }
}
