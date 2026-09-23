<?php

declare(strict_types=1);

use Firefly\OpenApi\Tests\Support\FixtureDocument;

/*
 | A Laravel paginator is written as the envelope its toArray() builds — `data` plus the paging members — and
 | the document published LengthAwarePaginator's one public property, `onEachSide`, instead. Expected shapes are
 | Laravel's own toArray() bodies (Illuminate\Pagination\*), member for member and in their order. Nothing about
 | them is generic PHPDoc a parser could read — the methods say only `@return array` — so they are stated once,
 | as the facts of the framework they are, the way springdoc knows Spring Data's Page.
 */
$document = static fn (): array => FixtureDocument::generatorFor('ReturnFixture')->generate();

/** @param  array<string, mixed>  $document */
function pagedReturn(array $document, string $path): mixed
{
    return FixtureDocument::resolve($document, '#/paths/~1paged'.str_replace('/', '~1', $path).'/get/responses/200/content/application~1json/schema');
}

it('documents a length-aware paginator as Laravel\'s envelope around the element type', function () use ($document) {
    $doc = $document();

    /** @var array<string, mixed> $component */
    $component = FixtureDocument::resolve($doc, '#/components/schemas/LengthAwarePaginatorParcel');
    unset($component['description']);

    $nullableInt = ['type' => ['integer', 'null']];
    $nullableString = ['type' => ['string', 'null']];

    expect(pagedReturn($doc, '/parcels'))->toBe(['$ref' => '#/components/schemas/LengthAwarePaginatorParcel'])
        ->and($component)->toBe([
            'title' => 'LengthAwarePaginator<Parcel>',
            'type' => 'object',
            'properties' => [
                'current_page' => ['type' => 'integer'],
                'data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Parcel']],
                'first_page_url' => ['type' => 'string'],
                'from' => $nullableInt,
                'last_page' => ['type' => 'integer'],
                'last_page_url' => ['type' => 'string'],
                'links' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'properties' => [
                        'url' => $nullableString,
                        'label' => ['type' => 'string'],
                        'page' => $nullableInt,
                        'active' => ['type' => 'boolean'],
                    ],
                    // The `...` separator link carries no `page`.
                    'required' => ['url', 'label', 'active'],
                ]],
                'next_page_url' => $nullableString,
                'path' => $nullableString,
                'per_page' => ['type' => 'integer'],
                'prev_page_url' => $nullableString,
                'to' => $nullableInt,
                'total' => ['type' => 'integer'],
            ],
            'required' => ['current_page', 'data', 'first_page_url', 'from', 'last_page', 'last_page_url', 'links', 'next_page_url', 'path', 'per_page', 'prev_page_url', 'to', 'total'],
        ]);
});

it('shares one component between the paginator class and its contract', function () use ($document) {
    expect(pagedReturn($document(), '/contract'))->toBe(['$ref' => '#/components/schemas/LengthAwarePaginatorParcel']);
});

it('documents a simple paginator and a cursor paginator with their own envelopes', function () use ($document) {
    $doc = $document();

    expect(pagedReturn($doc, '/labels'))->toBe(['$ref' => '#/components/schemas/PaginatorLabel'])
        ->and(array_keys((array) FixtureDocument::resolve($doc, '#/components/schemas/PaginatorLabel/properties')))
        ->toBe(['current_page', 'current_page_url', 'data', 'first_page_url', 'from', 'next_page_url', 'path', 'per_page', 'prev_page_url', 'to'])
        ->and(FixtureDocument::resolve($doc, '#/components/schemas/PaginatorLabel/properties/data'))
        ->toBe(['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Label']])
        ->and(pagedReturn($doc, '/cursor'))->toBe(['$ref' => '#/components/schemas/CursorPaginatorParcel'])
        ->and(array_keys((array) FixtureDocument::resolve($doc, '#/components/schemas/CursorPaginatorParcel/properties')))
        ->toBe(['data', 'path', 'per_page', 'next_cursor', 'next_page_url', 'prev_cursor', 'prev_page_url']);
});

it('uses the plain envelope when nothing says what the page holds', function () use ($document) {
    $doc = $document();

    expect(pagedReturn($doc, '/raw'))->toBe(['$ref' => '#/components/schemas/LengthAwarePaginator'])
        ->and(FixtureDocument::resolve($doc, '#/components/schemas/LengthAwarePaginator/properties/data'))->toBe(['type' => 'array']);
});

it('documents a paginator as JSON even though it can also render its links as HTML', function () use ($document) {
    // AbstractPaginator is Htmlable. It is also Arrayable, and ResponseFactory writes data before it renders.
    expect(FixtureDocument::resolve($document(), '#/paths/~1paged~1parcels/get/responses/200/content'))
        ->toHaveKey('application/json')
        ->not->toHaveKey('text/html');
});
