<?php

declare(strict_types=1);

namespace Firefly\Validation\Tests\Fixtures\Rule;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The rule the compiler CANNOT recover on its own: constructor state assigned in the body rather than
 * promoted, so no property mirrors the parameter and no reflection can reconstruct the argument list. It
 * exists to pin the compile-time failure — this must be a loud ConfigurationException naming the class and
 * the remedy, never a silent `new OpaqueConstructorRule()` at boot.
 */
final class OpaqueConstructorRule implements ValidationRule
{
    private string $needle;

    public function __construct(string $needle)
    {
        $this->needle = $needle;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! str_contains($value, $this->needle)) {
            $fail("The :attribute must contain {$this->needle}.");
        }
    }
}
