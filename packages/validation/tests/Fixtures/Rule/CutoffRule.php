<?php

declare(strict_types=1);

namespace Firefly\Validation\Tests\Fixtures\Rule;

use Closure;
use DateTimeImmutable;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Promoted constructor state that reflection CAN read but var_export CANNOT write: a DateTimeImmutable. It
 * pins the second half of the compile-time guard — recovering an argument is not enough, the argument must
 * also survive the generated PHP literal.
 */
final class CutoffRule implements ValidationRule
{
    public function __construct(private readonly DateTimeImmutable $cutoff) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || new DateTimeImmutable($value) > $this->cutoff) {
            $fail('The :attribute must not be after the cutoff.');
        }
    }
}
