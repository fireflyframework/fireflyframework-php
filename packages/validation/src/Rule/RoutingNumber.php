<?php

declare(strict_types=1);

namespace Firefly\Validation\Rule;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** US ABA routing/transit number: 9 digits with the 3-7-1 weighted mod-10 checksum. */
final class RoutingNumber implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $normalized = is_int($value) ? (string) $value : (is_string($value) ? $value : '');
        if (preg_match('/^\d{9}$/D', $normalized) !== 1) {
            $fail('The :attribute must be a valid ABA routing number.');

            return;
        }

        $d = array_map(intval(...), str_split($normalized));
        $checksum = 3 * ($d[0] + $d[3] + $d[6]) + 7 * ($d[1] + $d[4] + $d[7]) + ($d[2] + $d[5] + $d[8]);

        if ($checksum % 10 !== 0) {
            $fail('The :attribute must be a valid ABA routing number.');
        }
    }
}
