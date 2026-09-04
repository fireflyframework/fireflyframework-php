<?php

declare(strict_types=1);

use Firefly\OpenApi\Schema\DocType;
use Firefly\OpenApi\Tests\ResponseFixture\Consignment;
use Firefly\OpenApi\Tests\ResponseFixture\ConsignmentController;
use Firefly\OpenApi\Tests\ResponseFixture\Money;

/**
 * The PHPDoc type-expression parser, exercised directly rather than only through a generated document.
 *
 * The grammar is small but it is a grammar — balanced `<>` and `{}`, a comma that separates only at the
 * outer level, `?` meaning two different things depending on which side of a shape key it sits — and the
 * generator's output is downstream of every one of those decisions. A table here says what each spelling
 * means in one place, where a wrong answer reads as a wrong answer instead of as a puzzling schema.
 */
$ref = static fn (string $class): array => ['$ref' => '#/components/schemas/'.(str_contains($class, '\\') ? substr((string) strrchr($class, '\\'), 1) : $class)];

it('compiles scalars, unions and nullability', function () use ($ref) {
    expect(DocType::schema('int', $ref))->toBe(['type' => 'integer'])
        ->and(DocType::schema('?int', $ref))->toBe(['type' => ['integer', 'null']])
        ->and(DocType::schema('int|null', $ref))->toBe(['type' => ['integer', 'null']])
        ->and(DocType::schema('string|int', $ref))->toBe(['type' => ['string', 'integer']])
        ->and(DocType::schema('bool', $ref))->toBe(['type' => 'boolean'])
        // `mixed` is a real answer — JSON Schema's "any value" — and is the empty schema, not null.
        ->and(DocType::schema('mixed', $ref))->toBe([]);
});

it('keeps the extra information PHPStan pseudo-types carry', function () use ($ref) {
    expect(DocType::schema('non-empty-string', $ref))->toBe(['type' => 'string', 'minLength' => 1])
        ->and(DocType::schema('positive-int', $ref))->toBe(['type' => 'integer', 'minimum' => 1])
        ->and(DocType::schema('negative-int', $ref))->toBe(['type' => 'integer', 'maximum' => -1])
        ->and(DocType::schema('array-key', $ref))->toBe(['type' => ['string', 'integer']]);
});

it('collapses a union of literals into an enum', function () use ($ref) {
    expect(DocType::schema("'draft'|'sent'|'paid'", $ref))
        ->toBe(['type' => 'string', 'enum' => ['draft', 'sent', 'paid']])
        ->and(DocType::schema('1|2|3', $ref))
        ->toBe(['type' => 'integer', 'enum' => [1, 2, 3]]);
});

it('tells a list from a map, which a bare PHP array cannot', function () use ($ref) {
    // The decisive case. `array<int, T>` is a JSON array and `array<string, T>` is a JSON object; publishing
    // the second as an array is not vague but WRONG, and a generated client fails to decode the real payload.
    expect(DocType::schema('list<int>', $ref))->toBe(['type' => 'array', 'items' => ['type' => 'integer']])
        ->and(DocType::schema('array<int, int>', $ref))->toBe(['type' => 'array', 'items' => ['type' => 'integer']])
        ->and(DocType::schema('array<string, int>', $ref))->toBe(['type' => 'object', 'additionalProperties' => ['type' => 'integer']])
        ->and(DocType::schema('list<list<int>>', $ref))->toBe([
            'type' => 'array',
            'items' => ['type' => 'array', 'items' => ['type' => 'integer']],
        ])
        ->and(DocType::schema('non-empty-list<int>', $ref))
        ->toBe(['type' => 'array', 'items' => ['type' => 'integer'], 'minItems' => 1]);
});

it('reads an array shape, including which members are optional', function () use ($ref) {
    // A `?` on the KEY is "may be absent" and a `?` on the VALUE is "may be null". Conflating them documents
    // an omissible member as one a client must always send.
    expect(DocType::schema('array{a: int, b?: string, c: ?int}', $ref))->toBe([
        'type' => 'object',
        'properties' => [
            'a' => ['type' => 'integer'],
            'b' => ['type' => 'string'],
            'c' => ['type' => ['integer', 'null']],
        ],
        'required' => ['a', 'c'],
        'additionalProperties' => false,
    ]);

    // A trailing `...` is the author saying the shape is open, and is the only thing that lifts
    // `additionalProperties: false`.
    expect(DocType::schema('array{a: int, ...}', $ref) ?? [])->not->toHaveKey('additionalProperties');
});

it('reads a tuple shape as a positional array', function () use ($ref) {
    expect(DocType::schema('array{int, string}', $ref))->toBe([
        'type' => 'array',
        'prefixItems' => [['type' => 'integer'], ['type' => 'string']],
        'minItems' => 2,
        'maxItems' => 2,
    ]);
});

it('resolves a class through the imports of the file the expression was written in', function () use ($ref) {
    // `Money` is only a name; it means something because ConsignmentController imports it. Reflection does
    // not expose a file's `use` statements, so this is read from the source — and without it only a
    // fully-qualified name would resolve, which is the one spelling nobody writes.
    /** @var ReflectionClass<object> $context */
    $context = new ReflectionClass(ConsignmentController::class);

    expect(DocType::schema('Money', $ref, $context))->toBe(['$ref' => '#/components/schemas/Money'])
        ->and(DocType::schema('list<Consignment>', $ref, $context))
        ->toBe(['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Consignment']])
        ->and(DocType::schema('\\'.Money::class, $ref, $context))->toBe(['$ref' => '#/components/schemas/Money'])
        // A nullable reference cannot be widened in place: sibling validation keywords are applied WITH a
        // `$ref` in 2020-12, so a `type: null` beside it would have to hold as well and never could.
        ->and(DocType::schema('?Consignment', $ref, $context))
        ->toBe(['anyOf' => [['$ref' => '#/components/schemas/Consignment'], ['type' => 'null']]]);
});

it('says nothing rather than guessing when it cannot read the expression', function () use ($ref) {
    // Null is the signal for a caller to fall back to what it knew before it asked. It is a different answer
    // from the empty schema, which is a real "any value".
    expect(DocType::schema('NoSuchClassAnywhere', $ref))->toBeNull()
        ->and(DocType::schema('callable(int): string', $ref))->toBeNull()
        ->and(DocType::schema('array{unterminated: int', $ref))->toBeNull()
        ->and(DocType::schema('never', $ref))->toBeNull()
        // A list whose element does not resolve is still a list — the container is known even when the
        // contents are not.
        ->and(DocType::schema('list<NoSuchClass>', $ref))->toBe(['type' => 'array']);
});

it('splits a tag line into its type expression and the prose after it', function () use ($ref) {
    // Splitting on whitespace cannot work: a type expression contains spaces of its own. Whatever the
    // grammar consumed is the type; the rest is English.
    [$schema, $prose] = DocType::split('array{page: int, size: int} one page of results', $ref);

    /** @var array{properties: array<string, mixed>} $schema */
    expect($prose)->toBe('one page of results')
        ->and($schema['properties'])->toHaveKeys(['page', 'size']);

    /** @var ReflectionClass<object> $consignment */
    $consignment = new ReflectionClass(Consignment::class);
    [$schema, $prose] = DocType::split('Consignment', $ref, $consignment);

    expect($prose)->toBe('')
        ->and($schema)->toBe(['$ref' => '#/components/schemas/Consignment']);
});
