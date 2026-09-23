<?php

declare(strict_types=1);

use Firefly\OpenApi\Tests\Support\FixtureDocument;

/*
 | A declared return or member type that is not a single named type used to vanish. OperationFactory and
 | ResponseSchemaFactory read only ReflectionNamedType, so `Parcel|Label` became `{}` — "any value" — `?Parcel`
 | lost its null, and a union-typed constructor parameter on a request DTO was both untyped AND dropped from
 | `required`, although omitting it is an ArgumentCountError inside `new $dto(...)`: a 500 after validation
 | passed. The declared type now reaches the document through the same union and nullability rules a `@return`
 | expression already did, so the two spellings of one type produce one schema.
 |
 | Expected arm order is PHP's own: builtins canonicalised (`string` before `int`), classes in declaration
 | order, `null` last.
 */
$document = static fn (): array => FixtureDocument::generatorFor('ReturnFixture')->generate();

/** @param  array<string, mixed>  $document */
function unionReturn(array $document, string $path): mixed
{
    return FixtureDocument::resolve($document, '#/paths/~1unions'.str_replace('/', '~1', $path).'/get/responses/200/content/application~1json/schema');
}

it('keeps the null a nullable class return can answer with', function () use ($document) {
    expect(unionReturn($document(), '/optional'))
        ->toBe(['anyOf' => [['$ref' => '#/components/schemas/Parcel'], ['type' => 'null']]]);
});

it('documents every arm of a class union return', function () use ($document) {
    expect(unionReturn($document(), '/either'))
        ->toBe(['anyOf' => [['$ref' => '#/components/schemas/Parcel'], ['$ref' => '#/components/schemas/Label']]]);
});

it('spells a scalar union return as a type list', function () use ($document) {
    expect(unionReturn($document(), '/scalar'))->toBe(['type' => ['string', 'integer']]);
});

it('widens the bare-array fallback of a nullable array return rather than dropping the null', function () use ($document) {
    expect(unionReturn($document(), '/maybe-map'))->toBe(['type' => ['object', 'null']]);
});

it('documents stdClass and object returns as an open object, not as an empty component', function () use ($document) {
    $doc = $document();

    /** @var array{schemas: array<string, mixed>} $components */
    $components = $doc['components'];

    // `stdClass` has no declared members for reflection to find, so a component built from its public
    // properties is `properties: {}` — an object with NO members, the opposite of what it is.
    expect(unionReturn($doc, '/bag'))->toBe(['type' => 'object'])
        ->and(unionReturn($doc, '/object'))->toBe(['type' => 'object'])
        ->and($components['schemas'])->not->toHaveKey('stdClass');
});

it('documents union-typed members of a returned class', function () use ($document) {
    /** @var array{properties: array<string, mixed>, required: list<string>} $tagged */
    $tagged = FixtureDocument::resolve($document(), '#/components/schemas/Tagged');

    expect($tagged['properties']['ref'])->toBe(['type' => ['string', 'integer']])
        ->and($tagged['properties']['subject'])->toBe(['anyOf' => [
            ['$ref' => '#/components/schemas/Parcel'],
            ['$ref' => '#/components/schemas/Label'],
            ['type' => 'null'],
        ]])
        ->and($tagged['properties']['parcel'])->toBe(['anyOf' => [['$ref' => '#/components/schemas/Parcel'], ['type' => 'null']]])
        ->and($tagged['required'])->toBe(['ref', 'subject', 'parcel']);
});

it('types a union-typed request member and keeps it required', function () use ($document) {
    /** @var array{properties: array<string, mixed>, required: list<string>} $request */
    $request = FixtureDocument::resolve($document(), '#/components/schemas/TagRequest');

    // `int|string $ref` has no default and admits no null: ArgumentResolver splats only the keys the body
    // carried, so omitting it is an ArgumentCountError. It is as required as any `int $ref` would be.
    expect($request['properties']['ref'])->toBe(['type' => ['string', 'integer']])
        ->and($request['required'])->toBe(['ref']);
});
