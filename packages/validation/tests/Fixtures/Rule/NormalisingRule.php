<?php

declare(strict_types=1);

namespace Firefly\Validation\Tests\Fixtures\Rule;

use Closure;
use Firefly\Validation\Rule\Compilable;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The escape hatch from the escape hatch: a rule whose constructor TRANSFORMS its argument (upper-casing the
 * needle) and stores it unpromoted, so reflection could never rebuild the call. Implementing Compilable lets
 * it declare its own argument list, and the transform is idempotent so compile -> rehydrate is stable.
 */
final class NormalisingRule implements Compilable, ValidationRule
{
    private readonly string $needle;

    public function __construct(string $needle)
    {
        $this->needle = mb_strtoupper($needle);
    }

    /**
     * @return list<mixed>
     */
    public function constructorArguments(): array
    {
        return [$this->needle];
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! str_contains(mb_strtoupper($value), $this->needle)) {
            $fail("The :attribute must contain {$this->needle}.");
        }
    }
}
