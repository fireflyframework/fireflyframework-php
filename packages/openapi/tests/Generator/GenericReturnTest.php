<?php

declare(strict_types=1);

use Firefly\OpenApi\Tests\Support\FixtureDocument;

/*
 | `@return Page<Order>` — firefly/data's own paging type, and the return of every paged endpoint — used to be
 | documented as a bare `Page` whose `items` was `{type: array}`: an array of anything. DocType dropped the type
 | arguments of every generic over a user class, on the grounds that naming the instantiation (`PageOrder`)
 | would mint a component no source file contains. springdoc, the parity target, does exactly that, and the
 | alternative was a document that could not say what a page is a page OF. So an instantiation is now bound to
 | the class's own `@template` parameters, and becomes a component of its own named after its arguments.
 |
 | Batch stands in for Page (see its docblock): identical shape, in a namespace this package may import.
 */
$document = static fn (): array => FixtureDocument::generatorFor('ReturnFixture')->generate();

/** @param  array<string, mixed>  $document */
function genericReturn(array $document, string $path, string $status = '200'): mixed
{
    return FixtureDocument::resolve($document, '#/paths/~1generic'.str_replace('/', '~1', $path).'/get/responses/'.$status.'/content/application~1json/schema');
}

it('documents a generic instantiation as its own component, with the type argument bound', function () use ($document) {
    $doc = $document();

    expect(genericReturn($doc, '/parcels'))->toBe(['$ref' => '#/components/schemas/BatchParcel'])
        ->and(FixtureDocument::resolve($doc, '#/components/schemas/BatchParcel'))->toBe([
            'title' => 'Batch<Parcel>',
            'description' => "One batch of results and how many there are in all.\n\nShaped exactly like firefly/data's Page — a template parameter that only a constructor `@param` mentions — because that is the generic every paged endpoint returns, and it lives in a package this one may not import.",
            'type' => 'object',
            'properties' => [
                'items' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Parcel']],
                'total' => ['type' => 'integer'],
            ],
            'required' => ['items', 'total'],
        ]);
});

it('gives each instantiation its own component rather than one shared by all', function () use ($document) {
    $doc = $document();

    expect(genericReturn($doc, '/labels'))->toBe(['$ref' => '#/components/schemas/BatchLabel'])
        ->and(FixtureDocument::resolve($doc, '#/components/schemas/BatchLabel/properties/items'))
        ->toBe(['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Label']]);
});

it('names a scalar instantiation after its JSON type', function () use ($document) {
    $doc = $document();

    expect(genericReturn($doc, '/codes'))->toBe(['$ref' => '#/components/schemas/BatchString'])
        ->and(FixtureDocument::resolve($doc, '#/components/schemas/BatchString/properties/items'))
        ->toBe(['type' => 'array', 'items' => ['type' => 'string']]);
});

it('inlines an instantiation whose argument has no name to give it', function () use ($document) {
    // `list<Parcel>` has no component to be named after, and inventing `BatchListParcel` would be a name no
    // reader could predict. The specialised schema is written in place instead.
    $schema = genericReturn($document(), '/groups');

    expect($schema)->toBeArray()
        ->and($schema)->not->toHaveKey('$ref')
        ->and(FixtureDocument::resolve(['s' => $schema], '#/s/properties/items'))
        ->toBe(['type' => 'array', 'items' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Parcel']]]);
});

it('uses the plain component when the argument says nothing', function () use ($document) {
    $doc = $document();

    // `Batch<mixed>` binds T to "any value", which is what an unbound T already means: no second component.
    expect(genericReturn($doc, '/anything'))->toBe(['$ref' => '#/components/schemas/Batch'])
        ->and(FixtureDocument::resolve($doc, '#/components/schemas/Batch/properties/items'))->toBe(['type' => 'array']);
});

it('binds a template a subclass fixes through @extends', function () use ($document) {
    $doc = $document();

    // `data` is declared on Envelope as TData; ParcelEnvelope says `@extends Envelope<Parcel>`.
    expect(genericReturn($doc, '/enveloped'))->toBe(['$ref' => '#/components/schemas/ParcelEnvelope'])
        ->and(FixtureDocument::resolve($doc, '#/components/schemas/ParcelEnvelope/properties/data'))
        ->toBe(['$ref' => '#/components/schemas/Parcel']);
});

it('binds the same instantiation named in an #[ApiResponse] type', function () use ($document) {
    expect(genericReturn($document(), '/parcels', '206'))->toBe(['$ref' => '#/components/schemas/BatchParcel']);
});

it('reads any Laravel collection generic as the list it serialises to, not just one spelled Collection', function () use ($document) {
    // DocType recognised collections by NAME — `Collection<int, Parcel>` worked because its short name is
    // `collection` — so a LazyCollection, an aliased import or a fully-qualified spelling fell through to
    // reflecting the class's public properties, of which it has none: an object with no members.
    expect(genericReturn($document(), '/collected'))
        ->toBe(['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Parcel']]);
});

it('closes a self-referencing instantiation into a cycle through its own component', function () use ($document) {
    $doc = $document();

    // Tree<Parcel>'s children are Tree<Parcel>: the named component is reserved before it is built, so the
    // inner reference finds it and the expansion stops.
    expect(genericReturn($doc, '/tree'))->toBe(['$ref' => '#/components/schemas/TreeParcel'])
        ->and(FixtureDocument::resolve($doc, '#/components/schemas/TreeParcel/properties/children'))
        ->toBe(['type' => 'array', 'items' => ['$ref' => '#/components/schemas/TreeParcel']]);
});

it('stops an inline self-referencing instantiation at the plain component', function () use ($document) {
    // Tree<list<Parcel>> has no name, so there is no component to close the cycle through: the inner
    // Tree<list<Parcel>> degrades to the plain Tree rather than expanding forever.
    $schema = genericReturn($document(), '/forest');

    expect(FixtureDocument::resolve(['s' => $schema], '#/s/properties/children'))
        ->toBe(['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Tree']])
        ->and(FixtureDocument::resolve(['s' => $schema], '#/s/properties/value'))
        ->toBe(['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Parcel']]);
});

it('leaves no dangling reference behind', function () use ($document) {
    $doc = $document();

    foreach (array_unique(FixtureDocument::refs($doc)) as $ref) {
        expect(FixtureDocument::resolve($doc, $ref))->not->toBeNull("dangling \$ref {$ref}");
    }
});
