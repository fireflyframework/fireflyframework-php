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

        // The \D strip below would silently drop a trailing newline (or any control char), so
        // "…13\n" would checksum and pass. Reject control chars up front while still tolerating the
        // legitimate space/dash separators of card-style input, which the \D strip normalises away.
        if (is_string($value) && preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
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
