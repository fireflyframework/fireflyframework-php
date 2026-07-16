<?php

declare(strict_types=1);

namespace Firefly\Validation\Rule;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** E.164 telephone number: a leading + and up to 15 digits, first digit non-zero. */
final class E164 implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match('/^\+[1-9]\d{1,14}$/', $value) !== 1) {
            $fail('The :attribute must be a valid E.164 phone number.');
        }
    }
}
