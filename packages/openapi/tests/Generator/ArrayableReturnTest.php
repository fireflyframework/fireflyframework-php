<?php

declare(strict_types=1);

use Firefly\OpenApi\Tests\Support\FixtureDocument;

/*
 | JsonMessageConverter writes an Arrayable through toArray() — BEFORE it would honour JsonSerializable — and
 | the document ignored that entirely: it tried JsonSerializable first and otherwise published the class's
 | public properties. For an Eloquent model, the one Arrayable every LaraFly repository hands back, that meant
 | `incrementing`, `exists`, `timestamps`, `wasRecentlyCreated`, `preventsLazyLoading` and `usesUniqueIds`, all
 | REQUIRED, and not one of the model's columns. The wire shape is what toArray() returns, and this is where
 | the document reads it from.
 */
$document = static fn (): array => FixtureDocument::generatorFor('ReturnFixture')->generate();

/**
 * @param  array<string, mixed>  $document
 * @return array<string, mixed>
 */
function arrayableComponent(array $document, string $name): array
{
    $schema = FixtureDocument::resolve($document, '#/components/schemas/'.$name);
    if (! is_array($schema)) {
        return [];
    }
    unset($schema['title'], $schema['description']);

    /** @var array<string, mixed> $schema */
    return $schema;
}

it('documents an Arrayable from its toArray() shape, not from its public properties', function () use ($document) {
    expect(arrayableComponent($document(), 'Tally'))->toBe([
        'type' => 'object',
        'properties' => ['open' => ['type' => 'integer'], 'closed' => ['type' => 'integer']],
        'required' => ['open', 'closed'],
        'additionalProperties' => false,
    ]);
});

it('falls back to the value type an Arrayable declares through @implements', function () use ($document) {
    expect(arrayableComponent($document(), 'Counters'))->toBe(['type' => 'object', 'additionalProperties' => ['type' => 'integer']]);
});

it('says only "an object" when an Arrayable states nothing, rather than publishing its properties', function () use ($document) {
    expect(arrayableComponent($document(), 'Snapshot'))->toBe(['type' => 'object']);
});

it('prefers toArray() over jsonSerialize(), in the converter\'s own order', function () use ($document) {
    expect(array_keys((array) (arrayableComponent($document(), 'Dual')['properties'] ?? [])))->toBe(['source']);
});

it('documents an Eloquent model from its @property tags, minus what it hides', function () use ($document) {
    expect(arrayableComponent($document(), 'CrateEntity'))->toBe([
        'type' => 'object',
        'properties' => [
            'id' => ['type' => 'integer'],
            'label' => ['description' => 'the label printed on the crate', 'type' => 'string'],
            'status' => ['type' => 'string', 'enum' => ['open', 'closed']],
            'shipped_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
            'parcel_count' => ['type' => 'integer'],
            'display_name' => ['type' => 'string'],
        ],
        // A column (`@property`) is present whenever the row is; an appended accessor always is. A
        // `@property-read` that is not appended — a relation, a count — is there only when loaded.
        'required' => ['id', 'label', 'status', 'shipped_at', 'display_name'],
    ]);
});

it('documents an untagged model from what Eloquent itself is told: key, fillable, casts, timestamps, relations', function () use ($document) {
    $doc = $document();

    // Nothing here says whether a column is nullable, so every attribute but the key admits null; nor
    // whether an untyped fillable is a string, so it is left as any value rather than guessed.
    expect(arrayableComponent($doc, 'LooseEntity'))->toBe([
        'type' => 'object',
        'properties' => [
            'id' => ['type' => 'integer'],
            'label' => [],
            'weight' => ['type' => ['integer', 'null']],
            'status' => ['type' => ['string', 'null'], 'enum' => ['open', 'closed', null]],
            'shipped_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
            // Laravel writes a decimal cast as a STRING, to keep its scale.
            'price' => ['type' => ['string', 'null']],
            // A custom format is a string, but no longer an RFC 3339 date-time.
            'packed_on' => ['type' => ['string', 'null']],
            // `timestamp` is written as Unix seconds.
            'sealed_at' => ['type' => ['integer', 'null']],
            'created_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
            'updated_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
            // Relations appear under their snake_case name, and only when loaded.
            'crates' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/CrateEntity']],
            'parent_crate' => ['anyOf' => [['$ref' => '#/components/schemas/CrateEntity'], ['type' => 'null']]],
        ],
        'required' => ['id'],
    ]);
});

it('keeps only what a model\'s $visible allow-lists', function () use ($document) {
    expect(arrayableComponent($document(), 'BadgeEntity'))->toBe([
        'type' => 'object',
        'properties' => ['id' => ['type' => 'integer'], 'name' => ['type' => 'string']],
        'required' => ['id', 'name'],
    ]);
});

it('never documents Eloquent\'s own machinery as a member of a model', function () use ($document) {
    $doc = $document();

    foreach (['CrateEntity', 'LooseEntity'] as $model) {
        /** @var array<string, mixed> $properties */
        $properties = arrayableComponent($doc, $model)['properties'] ?? [];

        foreach (['incrementing', 'exists', 'timestamps', 'wasRecentlyCreated', 'preventsLazyLoading', 'usesUniqueIds', 'secret', 'password'] as $internal) {
            expect($properties)->not->toHaveKey($internal);
        }
    }
});
