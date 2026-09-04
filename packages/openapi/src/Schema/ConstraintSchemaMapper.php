<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Schema;

use Firefly\Validation\Rule\Bic;
use Firefly\Validation\Rule\CountryCode;
use Firefly\Validation\Rule\Currency;
use Firefly\Validation\Rule\Cusip;
use Firefly\Validation\Rule\DecimalScale;
use Firefly\Validation\Rule\E164;
use Firefly\Validation\Rule\Iban;
use Firefly\Validation\Rule\Isin;
use Firefly\Validation\Rule\LanguageTag;
use Firefly\Validation\Rule\Luhn;
use Firefly\Validation\Rule\NotNull;
use Firefly\Validation\Rule\Percentage;
use Firefly\Validation\Rule\PositiveMoney;
use Firefly\Validation\Rule\PostalCode;
use Firefly\Validation\Rule\RoutingNumber;
use Firefly\Validation\Rule\Size;
use Firefly\Validation\Rule\Swift;
use Firefly\Validation\Rule\Uuid;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Turns one property's compiled constraint list — the exact `list<string|ValidationRule>` that
 * ConstraintManifest::rulesFor() hands the BeanValidator at request time — into JSON Schema keywords.
 *
 * WHY THE MANIFEST, AND NOT THE #[Constraint] ATTRIBUTES. Reading the attributes back off the DTO would be
 * the obvious route to "#[Email] => format: email", and it would document a validator that does not exist.
 * The manifest is what actually runs: it has already applied ConstraintScanner's Jakarta null contract
 * (a `nullable` prepended to every property whose declared type admits null and which carries no NullAware
 * rule), already expanded #[Size] into a first-party rule OBJECT rather than Laravel's polymorphic
 * `min:`/`max:` strings, and already flattened one #[Valid] level into dotted keys. Generating from the
 * attributes would re-derive all of that by hand and drift from it the first time packages/validation
 * changes a toRules() body. Generating from the manifest cannot drift, because the manifest IS the contract.
 *
 * WHAT `required` MEANS HERE. Jakarta and JSON Schema agree that presence and nullability are different
 * questions, and this mapper keeps them apart: `required`/`present` (emitted by #[NotBlank]/#[NotEmpty]/
 * #[NotNull]) put the member in the parent's `required` list, while the `nullable` flag decides whether
 * `null` joins the member's own `type`. #[NotNull] does both at once — it is required AND not nullable —
 * which is why the NullAware NotNull rule object clears nullability rather than merely adding requiredness.
 *
 * LOSSLESSNESS. Several rules have no JSON Schema equivalent at all (`after:now` — JSON Schema cannot say
 * "in the future"; a bare Luhn checksum; a third-party ValidationRule the generator has never heard of), and
 * a few map only approximately (a PCRE pattern carrying flags ECMA-262 has no syntax for). Rather than drop
 * those silently — which would produce a spec that quietly promises less validation than the server
 * performs — each one is recorded under the `x-firefly-constraints` extension. Specification extensions are
 * explicitly permitted by OpenAPI 3.1 and ignored by every conforming tool, so the document stays valid
 * while the full truth survives for a human or a custom generator to read.
 *
 * FORMATS. `format` in JSON Schema 2020-12 is an open vocabulary: unknown values are annotations, not
 * errors. IBAN/BIC/ISIN/CUSIP/E.164 have no registered format name, so they are emitted as self-describing
 * ones (`iban`, `bic`, ...). Where a rule matches its PCRE against the RAW value, the pattern is emitted
 * too; where the rule NORMALISES first (Iban strips spaces and upper-cases; Bic/Swift/Cusip/Isin upper-case;
 * Luhn/RoutingNumber strip separators), the pattern is deliberately withheld — publishing the post-
 * normalisation pattern would reject payloads the server accepts, which is worse than under-specifying.
 */
final class ConstraintSchemaMapper
{
    public const string EXTENSION = 'x-firefly-constraints';

    /**
     * @param  array<string, mixed>  $base  the declared-type fragment from TypeSchema (may be empty)
     * @param  list<string|ValidationRule>  $rules  the compiled rule list for this property
     * @param  bool  $declaredNullable  whether the PHP declaration admits null
     * @param  bool  $declaredRequired  whether omitting the member would break DTO construction outright
     */
    public function apply(array $base, array $rules, bool $declaredNullable = false, bool $declaredRequired = false): PropertySchema
    {
        $state = new MapperState($base, $declaredNullable, $declaredRequired);

        foreach ($rules as $rule) {
            if (is_string($rule)) {
                $this->applyString($state, $rule);

                continue;
            }

            $this->applyObject($state, $rule);
        }

        return $state->finish();
    }

    private function applyString(MapperState $state, string $rule): void
    {
        [$name, $argument] = str_contains($rule, ':') ? explode(':', $rule, 2) : [$rule, ''];

        switch ($name) {
            case 'nullable':
                $state->nullable = true;

                return;
            case 'required':
            case 'present':
            case 'filled':
                $state->required = true;

                return;
            case 'string':
                $state->type('string');

                return;
            case 'numeric':
                $state->type('number');

                return;
            case 'integer':
            case 'int':
                $state->type('integer');

                return;
            case 'boolean':
                $state->type('boolean');

                return;
            case 'array':
                $state->type('array');

                return;
            case 'email':
                $state->type('string');
                $state->keyword('format', 'email');

                return;
            case 'url':
            case 'active_url':
                $state->type('string');
                $state->keyword('format', 'uri');

                return;
            case 'uuid':
                $state->type('string');
                $state->keyword('format', 'uuid');

                return;
            case 'ip':
                $state->type('string');
                $state->keyword('format', 'ipv4');

                return;
            case 'date':
            case 'date_format':
                $state->type('string');
                $state->keyword('format', 'date-time');

                return;
            case 'regex':
                $state->pattern($argument);

                return;
            case 'gte':
                $state->number('minimum', $argument);

                return;
            case 'lte':
                $state->number('maximum', $argument);

                return;
            case 'gt':
                $state->number('exclusiveMinimum', $argument);

                return;
            case 'lt':
                $state->number('exclusiveMaximum', $argument);

                return;
            case 'min':
                $state->bound($argument, min: true);

                return;
            case 'max':
                $state->bound($argument, min: false);

                return;
            case 'between':
                $bounds = explode(',', $argument);
                $state->bound(trim($bounds[0]), min: true);
                $state->bound(trim($bounds[1] ?? ''), min: false);

                return;
            case 'size':
                $state->bound($argument, min: true);
                $state->bound($argument, min: false);

                return;
            case 'in':
                $state->keyword('enum', array_map('trim', explode(',', $argument)));

                return;
            case 'accepted':
                $state->type('boolean');
                $state->keyword('const', true);

                return;
            case 'declined':
                $state->type('boolean');
                $state->keyword('const', false);

                return;
            default:
                // `after:now`, `before:now`, `exists:`, `unique:` and every unrecognised Laravel rule reach
                // here: real, enforced constraints that JSON Schema simply cannot state.
                $state->unmapped($rule);
        }
    }

    private function applyObject(MapperState $state, ValidationRule $rule): void
    {
        switch (true) {
            case $rule instanceof NotNull:
                // Required AND non-nullable — the one rule that answers both questions (see class docblock).
                $state->required = true;
                $state->nullable = false;

                return;
            case $rule instanceof Size:
                $state->size($rule->min(), $rule->max());

                return;
            case $rule instanceof Uuid:
                $state->type('string');
                $state->keyword('format', 'uuid');
                $state->pattern('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/');

                return;
            case $rule instanceof E164:
                $state->type('string');
                $state->keyword('format', 'phone');
                $state->pattern('/^\+[1-9]\d{1,14}$/');

                return;
            case $rule instanceof Currency:
                $state->type('string');
                $state->keyword('format', 'currency');
                $state->pattern('/^[A-Z]{3}$/');

                return;
            case $rule instanceof CountryCode:
                $state->type('string');
                $state->keyword('format', 'country-code');
                $state->pattern('/^[A-Z]{2}$/');

                return;
            case $rule instanceof LanguageTag:
                $state->type('string');
                $state->keyword('format', 'bcp47');
                $state->pattern('/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})*$/');

                return;
            case $rule instanceof PostalCode:
                $state->type('string');
                $state->keyword('format', 'postal-code');
                $state->pattern('/^[A-Za-z0-9][A-Za-z0-9 -]{1,9}$/');

                return;
            case $rule instanceof Iban:
                $state->type('string');
                $state->keyword('format', 'iban');
                $state->unmapped('iban:checksum');

                return;
            case $rule instanceof Swift:
                $state->type('string');
                $state->keyword('format', 'swift');

                return;
            case $rule instanceof Bic:
                $state->type('string');
                $state->keyword('format', 'bic');

                return;
            case $rule instanceof Cusip:
                $state->type('string');
                $state->keyword('format', 'cusip');
                $state->unmapped('cusip:check-digit');

                return;
            case $rule instanceof Isin:
                $state->type('string');
                $state->keyword('format', 'isin');
                $state->unmapped('isin:check-digit');

                return;
            case $rule instanceof Luhn:
                $state->keyword('format', 'luhn');
                $state->unmapped('luhn:check-digit');

                return;
            case $rule instanceof RoutingNumber:
                $state->type('string');
                $state->keyword('format', 'aba-routing-number');
                $state->unmapped('routing-number:check-digit');

                return;
            case $rule instanceof Percentage:
                $state->type('number');
                $state->keyword('minimum', 0);
                $state->keyword('maximum', 100);

                return;
            case $rule instanceof PositiveMoney:
                $state->type('number');
                $state->keyword('exclusiveMinimum', 0);
                $state->keyword('multipleOf', 0.01);

                return;
            case $rule instanceof DecimalScale:
                $state->keyword('multipleOf', $this->scaleStep($rule->scale()));

                return;
            default:
                // A third-party ValidationRule. Its class name is the only thing about it that is knowable
                // without executing it, so that is what the extension records.
                $state->unmapped($rule::class);
        }
    }

    /**
     * `multipleOf` for a decimal scale: 2 fractional digits => 0.01, 0 => 1. Computed as a division rather
     * than 10 ** -$scale so the value round-trips through json_encode as 0.01 instead of 1.0E-2 — both are
     * legal JSON numbers, but only the former reads as money in a rendered spec.
     */
    private function scaleStep(int $scale): float|int
    {
        return $scale <= 0 ? 1 : 1 / (10 ** $scale);
    }
}
