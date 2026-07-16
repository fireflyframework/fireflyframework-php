<?php

declare(strict_types=1);

namespace Firefly\Validation\Rule;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** A permissive international postal code: alphanumerics, spaces and hyphens, 2–10 chars, starting alphanumeric. */
final class PostalCode implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match('/^[A-Za-z0-9][A-Za-z0-9 -]{1,9}$/', $value) !== 1) {
            $fail('The :attribute must be a valid postal code.');
        }
    }
}
