<?php

declare(strict_types=1);

namespace Firefly\Validation\Rule;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** BCP-47 language tag (basic subtag grammar), e.g. `en`, `en-GB`, `zh-Hant-TW`. */
final class LanguageTag implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match('/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})*$/D', $value) !== 1) {
            $fail('The :attribute must be a valid BCP-47 language tag.');
        }
    }
}
