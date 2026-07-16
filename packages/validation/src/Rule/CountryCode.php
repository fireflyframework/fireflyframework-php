<?php

declare(strict_types=1);

namespace Firefly\Validation\Rule;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** ISO 3166-1 alpha-2 country code: two uppercase letters naming a known country. */
final class CountryCode implements ValidationRule
{
    /** @var list<string> representative ISO 3166-1 alpha-2 set (extend as needed — see README) */
    private const KNOWN = [
        'US', 'GB', 'ES', 'FR', 'DE', 'IT', 'PT', 'NL', 'BE', 'CH',
        'AT', 'IE', 'SE', 'NO', 'DK', 'FI', 'PL', 'CA', 'MX', 'BR',
        'AR', 'CL', 'CO', 'JP', 'CN', 'IN', 'AU', 'NZ', 'ZA', 'SG',
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match('/^[A-Z]{2}$/D', $value) !== 1 || ! in_array($value, self::KNOWN, true)) {
            $fail('The :attribute must be a valid ISO 3166-1 alpha-2 country code.');
        }
    }
}
