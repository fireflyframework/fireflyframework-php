<?php

declare(strict_types=1);

namespace Firefly\Validation\Rule;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** ISO 4217 currency code: three uppercase letters that name a known currency. */
final class Currency implements ValidationRule
{
    /** @var list<string> representative ISO 4217 set (extend as needed — see README) */
    private const KNOWN = [
        'USD', 'EUR', 'GBP', 'JPY', 'CHF', 'AUD', 'CAD', 'CNY', 'HKD', 'SGD',
        'SEK', 'NOK', 'DKK', 'MXN', 'BRL', 'ZAR', 'INR', 'RUB', 'KRW', 'PLN',
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match('/^[A-Z]{3}$/', $value) !== 1 || ! in_array($value, self::KNOWN, true)) {
            $fail('The :attribute must be a valid ISO 4217 currency code.');
        }
    }
}
