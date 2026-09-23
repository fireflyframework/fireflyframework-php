<?php

declare(strict_types=1);

use Firefly\OpenApi\Tests\Support\FixtureDocument;

/*
 | A Laravel API resource is a Responsable: ResponseFactory asks it for its own response, and ResourceResponse
 | writes what resolve() returns — wrapped under the resource class's static `$wrap` (`data` unless the
 | application turned wrapping off). The document published the resource OBJECT instead: `resource`, `with`
 | and `additional` for a JsonResource, plus `collects`, `preserveKeys` and `collection` for a collection.
 |
 | Inside another payload a resource is NOT wrapped — JsonResource::jsonSerialize() is resolve() alone — so a
 | resource's component is its bare shape and the envelope belongs to the response that returns it.
 */
$document = static fn (): array => FixtureDocument::generatorFor('ReturnFixture')->generate();

/** @param  array<string, mixed>  $document */
function resourceReturn(array $document, string $path, string $status = '200', string $verb = 'get'): mixed
{
    return FixtureDocument::resolve($document, '#/paths/~1resources'.str_replace('/', '~1', $path).'/'.$verb.'/responses/'.$status.'/content/application~1json/schema');
}

/**
 * @param  array<string, mixed>  $inner
 * @return array<string, mixed>
 */
function dataEnvelope(array $inner): array
{
    return ['type' => 'object', 'properties' => ['data' => $inner], 'required' => ['data']];
}

it('documents a returned resource inside the data envelope, and its component as its bare toArray() shape', function () use ($document) {
    $doc = $document();

    /** @var array<string, mixed> $component */
    $component = FixtureDocument::resolve($doc, '#/components/schemas/ParcelResource');
    unset($component['title'], $component['description']);

    expect(resourceReturn($doc, '/parcel'))->toBe(dataEnvelope(['$ref' => '#/components/schemas/ParcelResource']))
        ->and($component)->toBe([
            'type' => 'object',
            'properties' => ['barcode' => ['type' => 'string'], 'grams' => ['type' => 'integer']],
            'required' => ['barcode', 'grams'],
            'additionalProperties' => false,
        ]);
});

it('wraps a resource named by an #[ApiResponse] type, and one on a derived 201, the same way', function () use ($document) {
    $doc = $document();
    $wrapped = dataEnvelope(['$ref' => '#/components/schemas/ParcelResource']);

    expect(resourceReturn($doc, '/parcel', '202'))->toBe($wrapped)
        ->and(resourceReturn($doc, '', '201', 'post'))->toBe($wrapped);
});

it('documents a resource that does not override toArray() as the model it mixes in', function () use ($document) {
    $doc = $document();

    /** @var array<string, mixed> $component */
    $component = FixtureDocument::resolve($doc, '#/components/schemas/CrateResource');

    expect(resourceReturn($doc, '/crate'))->toBe(dataEnvelope(['$ref' => '#/components/schemas/CrateResource']))
        ->and($component['$ref'] ?? null)->toBe('#/components/schemas/CrateEntity');
});

it('leaves a resource whose $wrap is null unwrapped', function () use ($document) {
    expect(resourceReturn($document(), '/note'))->toBe(['$ref' => '#/components/schemas/NoteResource']);
});

it('finds a collection\'s element by #[Collects], by $collects and by Laravel\'s naming convention', function () use ($document) {
    $doc = $document();
    $parcels = dataEnvelope(['type' => 'array', 'items' => ['$ref' => '#/components/schemas/ParcelResource']]);

    expect(resourceReturn($doc, '/parcels'))->toBe($parcels)
        ->and(resourceReturn($doc, '/manifest'))->toBe($parcels)
        ->and(resourceReturn($doc, '/stack'))->toBe(dataEnvelope(['type' => 'array', 'items' => ['$ref' => '#/components/schemas/CrateResource']]));
});

it('documents an anonymous collection as a list of unknown elements, since only runtime knows what it collects', function () use ($document) {
    expect(resourceReturn($document(), '/anonymous'))->toBe(dataEnvelope(['type' => 'array']));
});

it('mints no component out of a resource object\'s internals', function () use ($document) {
    /** @var array{schemas: array<string, mixed>} $components */
    $components = $document()['components'];

    foreach (['ParcelResourceCollection', 'ManifestCollection', 'StackCollection', 'AnonymousResourceCollection', 'JsonResource', 'ResourceCollection'] as $name) {
        expect($components['schemas'])->not->toHaveKey($name);
    }

    foreach (['ParcelResource', 'CrateResource', 'NoteResource'] as $resource) {
        $schema = $components['schemas'][$resource] ?? [];
        $properties = is_array($schema) && is_array($schema['properties'] ?? null) ? array_keys($schema['properties']) : [];

        expect($properties)->not->toContain('resource', 'with', 'additional');
    }
});
