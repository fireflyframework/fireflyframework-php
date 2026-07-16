<?php

declare(strict_types=1);

namespace Firefly\Validation\Rule;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** CUSIP: 8-char issue/issuer + 1 check digit (Luhn-like with the standard *, @, # extension values). */
final class Cusip implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match('/^[A-Z0-9*@#]{8}[0-9]$/', strtoupper($value)) !== 1) {
            $fail('The :attribute must be a valid CUSIP.');

            return;
        }

        $cusip = strtoupper($value);
        $sum = 0;
        for ($i = 0; $i < 8; $i++) {
            $char = $cusip[$i];
            if (ctype_digit($char)) {
                $v = (int) $char;
            } elseif (ctype_alpha($char)) {
                $v = ord($char) - 55;
            } else {
                $v = match ($char) {
                    '*' => 36,
                    '@' => 37,
                    '#' => 38,
                    default => 0,
                };
            }

            if ($i % 2 === 1) {
                $v *= 2;
            }

            $sum += intdiv($v, 10) + ($v % 10);
        }

        $check = (10 - ($sum % 10)) % 10;
        if ($check !== (int) $cusip[8]) {
            $fail('The :attribute must be a valid CUSIP.');
        }
    }
}
