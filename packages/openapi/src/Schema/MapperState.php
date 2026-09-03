<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Schema;

/**
 * The mutable accumulator ConstraintSchemaMapper drives while it walks one property's rule list. Split out of
 * the mapper so the mapper stays a flat, readable rule-to-keyword table and the fiddly parts — first-writer-
 * wins, PCRE-to-ECMA translation, size-vs-magnitude dispatch, the 3.1 nullable spelling — each live in one
 * named method with the reasoning attached.
 *
 * FIRST WRITER WINS, everywhere. The declared PHP type is seeded into the schema before any rule is seen, so
 * `#[Min(1)] int $quantity` keeps `type: integer` instead of being overwritten by the `numeric` rule string
 * #[Min] emits (which is `number` — a superset that would wrongly document 1.5 as acceptable). The same
 * ordering rule then applies among the rules themselves, matching declaration order, which is the order the
 * validator itself applies them in.
 */
final class MapperState
{
    public bool $nullable;

    public bool $required;

    /** @var array<string, mixed> */
    private array $schema;

    /** @var list<string> */
    private array $patterns = [];

    /** @var list<string> */
    private array $unmapped = [];

    /**
     * @param  array<string, mixed>  $base
     */
    public function __construct(array $base, bool $nullable, bool $required)
    {
        $this->schema = $base;
        $this->nullable = $nullable;
        $this->required = $required;
    }

    public function type(string $type): void
    {
        $this->keyword('type', $type);
    }

    public function keyword(string $keyword, mixed $value): void
    {
        if (! array_key_exists($keyword, $this->schema)) {
            $this->schema[$keyword] = $value;
        }
    }

    /**
     * A numeric rule argument (`gte:2.5`) as the JSON number it denotes. A non-numeric argument is not a
     * bound at all — it is a field reference (`gte:other_field`), which JSON Schema cannot express — so it is
     * recorded as unmapped rather than coerced to 0.
     */
    public function number(string $keyword, string $argument): void
    {
        if (! is_numeric($argument)) {
            $this->unmapped($keyword.':'.$argument);

            return;
        }

        $this->keyword($keyword, $this->numeric($argument));
    }

    /**
     * Laravel's `min:`/`max:`/`between:`/`size:` are deliberately polymorphic — Validator::getSize() reads
     * the VALUE for a numeric attribute and the LENGTH/COUNT otherwise — so the keyword they translate to
     * depends on the type already resolved for this property. Firefly's own #[Size] no longer emits these
     * (it compiles to a Size rule OBJECT precisely because the polymorphism was a defect: see that rule's
     * docblock), but #[Rules('min:3')] passes raw Laravel strings straight through, so the ambiguity is
     * still reachable and is resolved here the same way the validator resolves it.
     */
    public function bound(string $argument, bool $min): void
    {
        if (! is_numeric($argument)) {
            $this->unmapped(($min ? 'min:' : 'max:').$argument);

            return;
        }

        $value = $this->numeric($argument);

        if ($this->isNumericType()) {
            $this->keyword($min ? 'minimum' : 'maximum', $value);

            return;
        }

        $this->keyword($this->lengthKeyword($min), (int) $value);
    }

    /**
     * Firefly's #[Size] rule object, which always MEASURES (never compares magnitudes), so it needs no
     * numeric branch — only the string-vs-array choice of which measurement keyword names the same idea.
     */
    public function size(?int $min, ?int $max): void
    {
        if ($min !== null) {
            $this->keyword($this->lengthKeyword(true), $min);
        }

        if ($max !== null) {
            $this->keyword($this->lengthKeyword(false), $max);
        }
    }

    /**
     * Translates a PCRE pattern (delimiters + flags, the form #[Pattern] and every `regex:` rule carry) into
     * the bare ECMA-262 pattern JSON Schema's `pattern` keyword expects.
     *
     * `D` and `u` are dropped as genuine no-ops: ECMA `$` without `m` already anchors at end-of-input (which
     * is all `D` buys over PCRE's default), and JSON Schema patterns are already Unicode. Any OTHER flag —
     * `i` above all, which ECMA-262 has no inline syntax for inside a pattern string — cannot be carried
     * across, so the pattern is still emitted (it is the closest true statement available) AND the original
     * rule is recorded as unmapped, so a reader can see that the published pattern is stricter than the
     * server's. An unparseable pattern is recorded and otherwise ignored: a malformed `pattern` keyword
     * would break every consumer of the document, which is a far worse outcome than an absent one.
     */
    public function pattern(string $pcre): void
    {
        $delimiters = ['(' => ')', '[' => ']', '{' => '}', '<' => '>'];
        $open = substr($pcre, 0, 1);

        if ($open === '' || ctype_alnum($open) || $open === '\\') {
            $this->unmapped('regex:'.$pcre);

            return;
        }

        $close = $delimiters[$open] ?? $open;
        $end = strrpos($pcre, $close);

        if ($end === false || $end === 0) {
            $this->unmapped('regex:'.$pcre);

            return;
        }

        $body = substr($pcre, 1, $end - 1);
        $flags = str_replace(['D', 'u'], '', substr($pcre, $end + 1));

        if ($flags !== '') {
            $this->unmapped('regex:'.$pcre);
        }

        if (! in_array($body, $this->patterns, true)) {
            $this->patterns[] = $body;
        }
    }

    public function unmapped(string $descriptor): void
    {
        if (! in_array($descriptor, $this->unmapped, true)) {
            $this->unmapped[] = $descriptor;
        }
    }

    public function finish(): PropertySchema
    {
        $schema = $this->schema;

        // One pattern is the `pattern` keyword; several are an allOf of single-pattern subschemas, because
        // JSON Schema has exactly one `pattern` slot per schema object and #[NotBlank] + #[Pattern] on the
        // same property genuinely produces two (`\S` and the developer's own). Collapsing them by keeping
        // only the last would silently drop the non-blank guarantee.
        if (count($this->patterns) === 1) {
            $schema['pattern'] = $this->patterns[0];
        } elseif (count($this->patterns) > 1) {
            $schema['allOf'] = array_map(
                static fn (string $pattern): array => ['pattern' => $pattern],
                $this->patterns,
            );
        }

        if ($this->nullable) {
            $schema = $this->nullify($schema);
        }

        if ($this->unmapped !== []) {
            $schema[ConstraintSchemaMapper::EXTENSION] = $this->unmapped;
        }

        return new PropertySchema($schema, $this->required);
    }

    /**
     * OpenAPI 3.1 is JSON Schema 2020-12, which dropped 3.0's `nullable: true` keyword in favour of a type
     * UNION — `type: [string, 'null']`. A schema with no `type` at all already admits null, so it is left
     * alone; an `enum` must additionally gain the null member, because `type` widening alone would leave
     * null failing the enumeration and the property would be undocumentable-as-null in practice.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function nullify(array $schema): array
    {
        if (isset($schema['enum']) && is_array($schema['enum']) && ! in_array(null, $schema['enum'], true)) {
            $schema['enum'] = [...array_values($schema['enum']), null];
        }

        if (! isset($schema['type']) || ! is_string($schema['type'])) {
            return $schema;
        }

        $schema['type'] = [$schema['type'], 'null'];

        return $schema;
    }

    private function isNumericType(): bool
    {
        return in_array($this->schema['type'] ?? null, ['integer', 'number'], true);
    }

    private function lengthKeyword(bool $min): string
    {
        $array = ($this->schema['type'] ?? null) === 'array';

        return match (true) {
            $array && $min => 'minItems',
            $array => 'maxItems',
            $min => 'minLength',
            default => 'maxLength',
        };
    }

    private function numeric(string $argument): int|float
    {
        return str_contains($argument, '.') ? (float) $argument : (int) $argument;
    }
}
