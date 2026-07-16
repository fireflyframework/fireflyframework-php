<?php

declare(strict_types=1);

namespace Firefly\Validation\Rule;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** ISO 6166 ISIN: 2-letter country + 9-char NSIN + 1 check digit, validated by a Luhn over the digit expansion. */
final class Isin implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match('/^[A-Z]{2}[A-Z0-9]{9}[0-9]$/', strtoupper($value)) !== 1) {
            $fail('The :attribute must be a valid ISIN.');

            return;
        }

        $converted = '';
        foreach (str_split(strtoupper($value)) as $char) {
            $converted .= ctype_alpha($char) ? (string) (ord($char) - 55) : $char;
        }

        $sum = 0;
        $alt = false;
        for ($i = strlen($converted) - 1; $i >= 0; $i--) {
            $n = (int) $converted[$i];
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
            $fail('The :attribute must be a valid ISIN.');
        }
    }
}
