<?php

declare(strict_types=1);

namespace Firefly\Validation\Rule;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** ISO 13616 IBAN: structural check + the ISO 7064 mod-97 == 1 checksum. */
final class Iban implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // IBANs are legitimately written with internal spaces for readability, but nothing else:
        // restricting the RAW value to letters/digits/spaces (D-anchored) preserves that display
        // tolerance while rejecting a trailing newline or any other control char BEFORE the \s+ strip
        // below would silently swallow it — otherwise "GB82...\n" would checksum and pass as valid.
        if (! is_string($value) || preg_match('/^[A-Za-z0-9 ]+$/D', $value) !== 1) {
            $fail('The :attribute must be a valid IBAN.');

            return;
        }

        $iban = strtoupper((string) preg_replace('/\s+/', '', $value));
        if (preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/D', $iban) !== 1) {
            $fail('The :attribute must be a valid IBAN.');

            return;
        }

        $rearranged = substr($iban, 4).substr($iban, 0, 4);
        $numeric = '';
        foreach (str_split($rearranged) as $char) {
            $numeric .= ctype_alpha($char) ? (string) (ord($char) - 55) : $char;
        }

        $remainder = 0;
        foreach (str_split($numeric) as $digit) {
            $remainder = ($remainder * 10 + (int) $digit) % 97;
        }

        if ($remainder !== 1) {
            $fail('The :attribute must be a valid IBAN.');
        }
    }
}
