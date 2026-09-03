<?php

declare(strict_types=1);

use Firefly\OpenApi\Schema\ConstraintSchemaMapper;
use Firefly\OpenApi\Schema\PropertySchema;
use Firefly\Validation\Constraint\AssertTrue;
use Firefly\Validation\Constraint\Bic;
use Firefly\Validation\Constraint\Constraint;
use Firefly\Validation\Constraint\Cusip;
use Firefly\Validation\Constraint\Email;
use Firefly\Validation\Constraint\Future;
use Firefly\Validation\Constraint\Iban;
use Firefly\Validation\Constraint\Isin;
use Firefly\Validation\Constraint\Luhn;
use Firefly\Validation\Constraint\Max;
use Firefly\Validation\Constraint\Min;
use Firefly\Validation\Constraint\NotBlank;
use Firefly\Validation\Constraint\NotNull;
use Firefly\Validation\Constraint\Pattern;
use Firefly\Validation\Constraint\Percentage;
use Firefly\Validation\Constraint\RoutingNumber;
use Firefly\Validation\Constraint\Rules;
use Firefly\Validation\Constraint\Size;
use Firefly\Validation\Constraint\UuidValue;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Every case drives the mapper with the OUTPUT OF A REAL #[Constraint]'s toRules(), never with a hand-typed
 * rule list. If packages/validation changes what a constraint compiles to — as #[Size] already did once,
 * moving from Laravel's polymorphic `min:`/`max:` strings to a first-party rule object — these tests change
 * with it instead of quietly documenting a validator that no longer exists.
 */
/**
 * @param  array<string, mixed>  $base  the declared-type fragment TypeSchema would have produced
 * @param  list<string|ValidationRule>  $rules
 */
function openApiMapConstraints(array $base, array $rules, bool $nullable = false, bool $required = false): PropertySchema
{
    return (new ConstraintSchemaMapper)->apply($base, $rules, $nullable, $required);
}

it('makes #[NotBlank] a required, non-blank string', function () {
    $property = openApiMapConstraints(['type' => 'string'], (new NotBlank)->toRules());

    expect($property->required)->toBeTrue()
        ->and($property->schema)->toBe(['type' => 'string', 'pattern' => '\S']);
});

it('makes #[NotNull] required AND clears nullability, even on a nullable declaration', function () {
    // #[NotNull] is the one constraint that answers both questions Jakarta keeps separate. Its NullAware
    // rule object is also why ConstraintScanner withholds the `nullable` flag from the property entirely.
    $property = openApiMapConstraints(['type' => 'string'], (new NotNull)->toRules(), nullable: true);

    expect($property->required)->toBeTrue()
        ->and($property->schema)->toBe(['type' => 'string']);
});

it('spells a nullable member as a 3.1 type union and widens an enum with null', function () {
    $string = openApiMapConstraints(['type' => 'string'], ['nullable', ...(new Email)->toRules()]);
    $enum = openApiMapConstraints(['type' => 'string', 'enum' => ['EUR']], ['nullable']);

    expect($string->schema)->toBe(['type' => ['string', 'null'], 'format' => 'email'])
        ->and($string->required)->toBeFalse()
        ->and($enum->schema)->toBe(['type' => ['string', 'null'], 'enum' => ['EUR', null]]);
});

it('measures #[Size] as a length on a string and as a count on an array', function () {
    $rules = (new Size(min: 2, max: 10))->toRules();

    expect(openApiMapConstraints(['type' => 'string'], $rules)->schema)->toBe(['type' => 'string', 'minLength' => 2, 'maxLength' => 10])
        ->and(openApiMapConstraints(['type' => 'array'], $rules)->schema)->toBe(['type' => 'array', 'minItems' => 2, 'maxItems' => 10]);
});

it('keeps the declared integer type through #[Min]/#[Max], which only say `numeric`', function () {
    // The rule list here is ['numeric', 'gte:1', 'numeric', 'lte:9']. `number` would be a SUPERSET of the
    // declared int and would document 1.5 as acceptable, so first-writer-wins must leave `integer` standing.
    $property = openApiMapConstraints(['type' => 'integer'], [...(new Min(1))->toRules(), ...(new Max(9))->toRules()]);

    expect($property->schema)->toBe(['type' => 'integer', 'minimum' => 1, 'maximum' => 9]);
});

it('translates a PCRE pattern to a bare ECMA-262 pattern, dropping only the no-op flags', function () {
    $property = openApiMapConstraints(['type' => 'string'], (new Pattern('/^[A-Z]{3}$/D'))->toRules());

    expect($property->schema)->toBe(['type' => 'string', 'pattern' => '^[A-Z]{3}$']);
});

it('still emits a flagged pattern but records the loss, because ECMA-262 has no inline flags', function () {
    $property = openApiMapConstraints(['type' => 'string'], (new Pattern('/^abc$/i'))->toRules());

    expect($property->schema['pattern'])->toBe('^abc$')
        ->and($property->schema[ConstraintSchemaMapper::EXTENSION])->toBe(['regex:/^abc$/i']);
});

it('collects two patterns into an allOf rather than letting one overwrite the other', function () {
    // #[NotBlank] contributes `\S` and #[Pattern] contributes the developer's own; JSON Schema has exactly
    // one `pattern` slot, so keeping only the last would silently drop the non-blank guarantee.
    $property = openApiMapConstraints(['type' => 'string'], [...(new NotBlank)->toRules(), ...(new Pattern('/^[a-z]+$/D'))->toRules()]);

    expect($property->schema['allOf'])->toBe([['pattern' => '\S'], ['pattern' => '^[a-z]+$']])
        ->and($property->schema)->not->toHaveKey('pattern');
});

it('records a constraint JSON Schema cannot state instead of dropping it', function () {
    // JSON Schema has no way to say "in the future"; the server enforces it regardless, so the document
    // must not imply otherwise.
    $property = openApiMapConstraints(['type' => 'string'], (new Future)->toRules());

    expect($property->schema)->toMatchArray(['type' => 'string', 'format' => 'date-time'])
        ->and($property->schema[ConstraintSchemaMapper::EXTENSION])->toBe(['after:now']);
});

it('records an unrecognised third-party rule by class name', function () {
    $rule = new class implements ValidationRule
    {
        public function validate(string $attribute, mixed $value, Closure $fail): void {}
    };

    $property = openApiMapConstraints([], [$rule]);

    expect($property->schema[ConstraintSchemaMapper::EXTENSION])->toBe([$rule::class]);
});

it('maps the first-party rule objects onto formats and bounds', function () {
    $uuid = openApiMapConstraints(['type' => 'string'], (new UuidValue)->toRules());
    $percentage = openApiMapConstraints([], (new Percentage)->toRules());
    $accepted = openApiMapConstraints([], (new AssertTrue)->toRules());

    expect($uuid->schema['format'])->toBe('uuid')
        ->and($percentage->schema)->toBe(['type' => 'number', 'minimum' => 0, 'maximum' => 100])
        ->and($accepted->schema)->toBe(['type' => 'boolean', 'const' => true]);
});

it('resolves Laravel\'s polymorphic min:/max: the way the validator resolves them', function () {
    // #[Rules] passes raw Laravel strings straight through, and `min:3` means LENGTH on a string but
    // MAGNITUDE on a number — Validator::getSize()'s own rule, applied here from the resolved type.
    $string = openApiMapConstraints(['type' => 'string'], (new Rules('min:3'))->toRules());
    $number = openApiMapConstraints(['type' => 'integer'], (new Rules('min:3'))->toRules());

    expect($string->schema)->toBe(['type' => 'string', 'minLength' => 3])
        ->and($number->schema)->toBe(['type' => 'integer', 'minimum' => 3]);
});

it('records a bound that names another field rather than coercing it to zero', function () {
    $property = openApiMapConstraints(['type' => 'integer'], (new Rules('gte:other_field'))->toRules());

    expect($property->schema)->toBe(['type' => 'integer', ConstraintSchemaMapper::EXTENSION => ['minimum:other_field']]);
});

it('treats a declaration with no default and no null as required even with no constraint', function () {
    // ArgumentResolver splats only the keys the body carried, so omitting such a member raises
    // ArgumentCountError inside `new $dto(...)` — a 500 AFTER validation passed. Documenting it as optional
    // would hand a client a legal-looking request the server cannot serve.
    expect(openApiMapConstraints(['type' => 'string'], [], nullable: false, required: true)->required)->toBeTrue()
        ->and(openApiMapConstraints(['type' => 'string'], [], nullable: false, required: false)->required)->toBeFalse();
});

it('withholds a pattern for every rule that NORMALISES the value before matching', function (Constraint $constraint, string $format, ?string $unmappable) {
    $property = openApiMapConstraints(['type' => 'string'], $constraint->toRules());

    // Iban strips whitespace and upper-cases; Bic/Isin/Cusip upper-case; Luhn/RoutingNumber strip every
    // non-digit. Each rule's own preg_match therefore runs against a string the CLIENT never sent, so
    // publishing that post-normalisation pattern would tell a generated client to reject `de89 3704 ...`
    // — a payload this server accepts. Under-specifying is the only honest option, so `format` is emitted
    // and `pattern` deliberately is not.
    expect($property->schema)->not->toHaveKey('pattern')
        ->and($property->schema)->not->toHaveKey('allOf')
        ->and($property->schema['format'])->toBe($format);

    // The check digit is arithmetic, which JSON Schema cannot state at all, so it is RECORDED rather than
    // dropped silently — the losslessness rule this package is built on.
    if ($unmappable !== null) {
        expect($property->schema[ConstraintSchemaMapper::EXTENSION])->toContain($unmappable);
    }
})->with([
    'iban' => [new Iban, 'iban', 'iban:checksum'],
    'bic' => [new Bic, 'bic', null],
    'isin' => [new Isin, 'isin', 'isin:check-digit'],
    'cusip' => [new Cusip, 'cusip', 'cusip:check-digit'],
    'luhn' => [new Luhn, 'luhn', 'luhn:check-digit'],
    'routing number' => [new RoutingNumber, 'aba-routing-number', 'routing-number:check-digit'],
]);
