<?php

declare(strict_types=1);

namespace Firefly\Validation\Tests\Fixtures\Rule;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A stand-in for the third-party/app-local rule an app reaches for through the #[Rules] escape hatch: it
 * carries CONSTRUCTOR STATE, promoted to readonly properties (the ordinary PHP 8 shape). It exists to prove
 * the compiled manifest round-trips that state instead of rehydrating a defaulted, differently-behaving rule.
 */
final class StartsWith implements ValidationRule
{
    public function __construct(
        private readonly string $prefix,
        private readonly bool $caseSensitive = true,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $subject = is_string($value) ? $value : '';

        $matches = $this->caseSensitive
            ? str_starts_with($subject, $this->prefix)
            : str_starts_with(mb_strtolower($subject), mb_strtolower($this->prefix));

        if (! $matches) {
            $fail("The :attribute must start with {$this->prefix}.");
        }
    }
}
