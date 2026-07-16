<?php

declare(strict_types=1);

namespace Firefly\Validation\Rule;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** RFC 4122 UUID (any version), case-insensitive. */
final class Uuid implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
        if (! is_string($value) || preg_match($pattern, $value) !== 1) {
            $fail('The :attribute must be a valid UUID.');
        }
    }
}
