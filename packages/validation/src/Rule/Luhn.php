<?php

declare(strict_types=1);

namespace Firefly\Validation\Rule;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Passes the Luhn (mod-10) checksum — credit-card / IMEI style. */
final class Luhn implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) && ! is_int($value)) {
            $fail('The :attribute must pass the Luhn checksum.');

            return;
        }

        $digits = (string) preg_replace('/\D/', '', (string) $value);
        if (strlen($digits) < 2) {
            $fail('The :attribute must pass the Luhn checksum.');

            return;
        }

        $sum = 0;
        $alt = false;
        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $n = (int) $digits[$i];
            if ($alt) {
                $n *= 2;
                if ($n > 9) {
                    $n -= 9;
                }
            }
            $sum += $n;
            $alt = ! $alt;
        }

        if ($sum % 10 !== 0) {
            $fail('The :attribute must pass the Luhn checksum.');
        }
    }
}
