<?php

declare(strict_types=1);

namespace Firefly\Validation\Rule;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** A percentage: a number in the inclusive range [0, 100]. */
final class Percentage implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value)) {
            // is_numeric() accepts leading/trailing whitespace in PHP 8 (e.g. "50\n" is numeric),
            // which would let a newline-bearing value flow through validated(); require a strict,
            // whitespace-free decimal string (D-anchored so a trailing newline cannot slip past $).
            if (preg_match('/^-?\d+(\.\d+)?$/D', $value) !== 1) {
                $fail('The :attribute must be a percentage between 0 and 100.');

                return;
            }
        } elseif (! is_int($value) && ! is_float($value)) {
            $fail('The :attribute must be a percentage between 0 and 100.');

            return;
        }

        $number = (float) $value;
        if ($number < 0.0 || $number > 100.0) {
            $fail('The :attribute must be a percentage between 0 and 100.');
        }
    }
}
