<?php

declare(strict_types=1);

namespace Firefly\Validation\Rule;

use Closure;
use Countable;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Jakarta's @Size, as a rule that MEASURES rather than compares.
 *
 * #[Size] used to compile to Laravel's `min:`/`max:`/`between:` strings, which are deliberately polymorphic:
 * Validator::getSize() returns the VALUE itself when the attribute is numeric and its rule list also carries
 * `numeric` or `integer`, and the string length / array count otherwise. That made the meaning of #[Size]
 * depend on its SIBLINGS. Add #[Min] (which emits `numeric`) next to #[Size(min: 3, max: 8)] and the compiled
 * list became ['numeric', 'gte:5', 'between:3,8'], at which point 7 — a single character — passed the length
 * check because the number 7 sits between 3 and 8, while 12345678 — eight characters, exactly in range — was
 * rejected because the number is larger than 8. One constraint silently changed semantics because an
 * unrelated one was declared beside it; the caught symptom was a length rule that neither rejected what it
 * should nor accepted what it should.
 *
 * A first-party rule object removes the coupling entirely: it never consults the sibling rule list, so
 * #[Size] means size, always. Measurement follows Jakarta's supported targets — CharSequence by character
 * count (mb_strlen, so a multibyte name is measured in characters, not bytes), Collection/array by element
 * count, plus Countable — with one deliberate extension: a JSON number arriving for a string-shaped field is
 * measured by the length of its decimal form, which is what the old rule strings did in the ABSENCE of a
 * `numeric` sibling and is therefore the behaviour existing payloads were written against. Anything with no
 * meaningful size (bool, object, resource) fails loudly rather than being coerced: @Size on such a field is a
 * modelling error, and quietly measuring strlen('1') would be one more silent semantic flip.
 *
 * Null returns early as valid — Jakarta gives @NotNull sole responsibility for rejecting null. The scanner
 * also prepends `nullable`, so this branch is belt-and-braces for a hand-assembled rule list.
 */
final class Size implements ValidationRule
{
    public function __construct(
        private readonly ?int $min = null,
        private readonly ?int $max = null,
    ) {}

    public function min(): ?int
    {
        return $this->min;
    }

    public function max(): ?int
    {
        return $this->max;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null) {
            return;
        }

        $size = $this->measure($value);

        if ($size === null) {
            $fail('The :attribute has no measurable size; a size constraint applies to strings, arrays and countables.');

            return;
        }

        if ($this->min !== null && $size < $this->min) {
            $fail($this->max !== null
                ? "The :attribute size must be between {$this->min} and {$this->max}."
                : "The :attribute size must be at least {$this->min}.");

            return;
        }

        if ($this->max !== null && $size > $this->max) {
            $fail($this->min !== null
                ? "The :attribute size must be between {$this->min} and {$this->max}."
                : "The :attribute size must not be greater than {$this->max}.");
        }
    }

    private function measure(mixed $value): ?int
    {
        if (is_string($value)) {
            return mb_strlen($value);
        }

        if (is_array($value) || $value instanceof Countable) {
            return count($value);
        }

        if (is_int($value) || is_float($value)) {
            return mb_strlen((string) $value);
        }

        return null;
    }
}
