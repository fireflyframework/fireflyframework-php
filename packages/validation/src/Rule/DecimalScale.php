<?php

declare(strict_types=1);

namespace Firefly\Validation\Rule;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** A decimal number with at most $scale fractional digits. */
final class DecimalScale implements ValidationRule
{
    public function __construct(private readonly int $scale) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $normalized = is_int($value) || is_float($value) ? (string) $value : (is_string($value) ? $value : '');
        $pattern = $this->scale > 0
            ? '/^-?\d+(\.\d{1,'.$this->scale.'})?$/'
            : '/^-?\d+$/';

        if (preg_match($pattern, $normalized) !== 1) {
            $fail("The :attribute must be a decimal with at most {$this->scale} fractional digit(s).");
        }
    }
}
