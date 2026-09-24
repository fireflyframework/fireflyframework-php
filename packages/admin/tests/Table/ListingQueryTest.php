<?php

declare(strict_types=1);

use Firefly\Admin\Data\DataFilter;
use Firefly\Admin\Table\ListingQuery;
use Firefly\Admin\Table\TableSettings;
use Illuminate\Http\Request;

/** @param array<string,mixed> $parameters */
function listingRequest(array $parameters = []): Request
{
    return Request::create('/firefly/mappings', 'GET', $parameters);
}

function listingQuery(Request $request, string ...$sortable): ListingQuery
{
    return ListingQuery::fromRequest($request, new TableSettings, '/firefly/mappings', array_values($sortable));
}

it('defaults to the first page at the configured size, unsorted and unsearched', function () {
    $query = listingQuery(listingRequest(), 'path');

    expect($query->page)->toBe(1)
        ->and($query->size)->toBe(50)
        ->and($query->sort)->toBeNull()
        ->and($query->direction)->toBe('asc')
        ->and($query->search)->toBeNull()
        ->and($query->isFiltered())->toBeFalse();
});

// CLAMPED, NEVER REDIRECTED: a bookmark to ?page=0 renders page 1 rather than answering 302, because a
// dashboard that rewrites the URL it was given makes the back button useless.
it('clamps a page that is zero, negative or not a number to the first page', function (string $page) {
    expect(listingQuery(listingRequest(['page' => $page]), 'path')->page)->toBe(1);
})->with([['0'], ['-3'], ['abc'], [''], ['1e3']]);

it('keeps a page number it was given, however far past the end', function () {
    expect(listingQuery(listingRequest(['page' => '999']), 'path')->page)->toBe(999);
});

it('accepts only a sort column the caller published', function () {
    expect(listingQuery(listingRequest(['sort' => 'path']), 'path', 'handler')->sort)->toBe('path')
        ->and(listingQuery(listingRequest(['sort' => 'password']), 'path', 'handler')->sort)->toBeNull()
        ->and(listingQuery(listingRequest(['sort' => 'path); drop table']), 'path')->sort)->toBeNull();
});

it('reads only the exact string desc as a descending sort', function (string $dir, string $expected) {
    expect(listingQuery(listingRequest(['sort' => 'path', 'dir' => $dir]), 'path')->direction)->toBe($expected);
})->with([['desc', 'desc'], ['asc', 'asc'], ['DESC', 'asc'], ['down', 'asc'], ['', 'asc']]);

it('trims the search term and caps its length', function () {
    expect(listingQuery(listingRequest(['q' => '   orders  ']), 'path')->search)->toBe('orders')
        ->and(listingQuery(listingRequest(['q' => '   ']), 'path')->search)->toBeNull()
        ->and(listingQuery(listingRequest(['q' => str_repeat('x', 500)]), 'path')->search)
        ->toHaveLength(ListingQuery::MAX_SEARCH_LENGTH);
});

it('falls back to the caller-declared default sort and direction', function () {
    $query = ListingQuery::fromRequest(
        listingRequest(),
        new TableSettings,
        '/firefly/http',
        ['timestamp', 'status'],
        defaultSort: 'timestamp',
        defaultDirection: 'desc',
    );

    expect($query->sort)->toBe('timestamp')->and($query->direction)->toBe('desc');
});

// THE POINT OF THE WHOLE OBJECT. Every link is rebuilt from PARSED values through http_build_query, so a
// sort that dropped the filter, or a page that dropped the size, is not expressible.
it('rebuilds a link from parsed values and omits every parameter that is at its default', function () {
    $query = listingQuery(listingRequest(), 'path');

    expect($query->link())->toBe('/firefly/mappings')
        ->and($query->link(['page' => 3]))->toBe('/firefly/mappings?page=3')
        ->and($query->link(['page' => 1]))->toBe('/firefly/mappings')
        ->and($query->link(['size' => 50]))->toBe('/firefly/mappings');
});

it('carries every piece of state across a link that changes one of them', function () {
    $query = listingQuery(listingRequest(['q' => 'orders', 'sort' => 'path', 'dir' => 'desc', 'size' => '25', 'page' => '4']), 'path');

    expect($query->link())->toBe('/firefly/mappings?q=orders&sort=path&dir=desc&size=25&page=4')
        ->and($query->link(['page' => 5]))->toBe('/firefly/mappings?q=orders&sort=path&dir=desc&size=25&page=5')
        ->and($query->link(['q' => null]))->toBe('/firefly/mappings?sort=path&dir=desc&size=25&page=4');
});

// Re-sorting returns to page one: page 4 of an ordering that no longer exists is a page of rows the reader
// never asked to skip.
it('returns to the first page when the sort changes, and flips the direction on the current column', function () {
    $query = listingQuery(listingRequest(['sort' => 'path', 'dir' => 'asc', 'page' => '4']), 'path', 'handler');

    expect($query->sortLink('path'))->toBe('/firefly/mappings?sort=path&dir=desc')
        ->and($query->sortLink('handler'))->toBe('/firefly/mappings?sort=handler')
        ->and($query->nextDirection('path'))->toBe('desc')
        ->and($query->nextDirection('handler'))->toBe('asc')
        ->and($query->indicator('path'))->toBe('↑')
        ->and($query->indicator('handler'))->toBe('');
});

it('carries parameters it does not own, including repeated ones', function () {
    $query = ListingQuery::fromRequest(
        Request::create('/firefly/data', 'GET', ['page' => '2']),
        new TableSettings,
        '/firefly/data',
        ['id'],
        carried: ['resource' => 'order-entity', 'fc' => ['customer'], 'fo' => ['contains'], 'fv' => ['Hopper']],
    );

    expect($query->link())->toContain('resource=order-entity')
        ->and($query->link())->toContain('fc%5B0%5D=customer')
        ->and($query->link())->toContain('page=2')
        ->and($query->link(['page' => null]))->not->toContain('page=');
});

// Two listings on one page (Conditions, Config properties) each need their own page/sort/q, so each takes a
// qualifier — the same shape Spring gives a second Pageable with @Qualifier.
it('qualifies its own parameters so two listings can share a page', function () {
    $request = Request::create('/firefly/conditions', 'GET', ['pos_page' => '3', 'neg_page' => '2', 'pos_sort' => 'class']);

    $applied = ListingQuery::fromRequest($request, new TableSettings, '/firefly/conditions', ['class'], qualifier: 'pos');
    $backed = ListingQuery::fromRequest($request, new TableSettings, '/firefly/conditions', ['class'], qualifier: 'neg');

    expect($applied->page)->toBe(3)
        ->and($applied->sort)->toBe('class')
        ->and($backed->page)->toBe(2)
        ->and($backed->sort)->toBeNull()
        ->and($applied->own())->toBe(['pos_sort' => 'class', 'pos_page' => '3'])
        ->and($applied->carrying($backed->own())->link())
        ->toBe('/firefly/conditions?neg_page=2&pos_sort=class&pos_page=3');
});

it('publishes its state as hidden fields for a GET search form, minus the term and the page', function () {
    $query = ListingQuery::fromRequest(
        Request::create('/firefly/data', 'GET', ['sort' => 'id', 'dir' => 'desc', 'size' => '25', 'page' => '4', 'q' => 'ada']),
        new TableSettings,
        '/firefly/data',
        ['id'],
        carried: ['resource' => 'order-entity', 'fc' => ['customer']],
    );

    expect($query->hiddenFields())->toBe([
        ['name' => 'resource', 'value' => 'order-entity'],
        ['name' => 'fc[]', 'value' => 'customer'],
        ['name' => 'sort', 'value' => 'id'],
        ['name' => 'dir', 'value' => 'desc'],
        ['name' => 'size', 'value' => '25'],
    ]);
});

/**
 * The shape `ListingQuery::$carried` is handed for the data browser's filters. It belongs beside the other
 * link-building assertions rather than in the data suite: what it pins is not how a filter QUERIES, it is
 * that a filter reaches a link builder as parsed parameters instead of as a hand-concatenated string.
 */
it('turns a filter set into query parameters rather than a hand-built string', function () {
    $filters = [
        new DataFilter('customer', DataFilter::CONTAINS, 'Hopper'),
        new DataFilter('total', DataFilter::GT, '5'),
    ];

    expect(DataFilter::toParameters($filters))->toBe([
        'fc' => ['customer', 'total'],
        'fo' => ['contains', 'gt'],
        'fv' => ['Hopper', '5'],
    ]);

    // The short spelling a relation link produces stays exactly what it was — it is short enough to read
    // in a status bar, and every relation link in the tree carries it.
    expect(DataFilter::toParameters([new DataFilter('order_id', DataFilter::EQ, '7')]))
        ->toBe(['fk' => 'order_id', 'fv' => '7']);

    expect(DataFilter::toParameters([]))->toBe([]);
});

// The string form is the same parameters, built by http_build_query rather than by concatenating
// urlencode() calls — one representation, two spellings of it.
it('keeps a query-string form that agrees with the parameters, escaping included', function () {
    $filters = [new DataFilter('customer', DataFilter::EQ, 'Grace Hopper')];

    expect(DataFilter::toQuery($filters))->toBe('fk=customer&fv=Grace+Hopper')
        ->and(DataFilter::toQuery([]))->toBe('');
});

/**
 * A listing may be SERVED at a size the query did not ask for. `DataBrowser::list()` applies
 * `firefly.admin.data.max-page-size` on top of the table's own offered set, because a size that is merely
 * large on an actuator payload materialises a whole table into PHP memory on a repository that cannot page.
 * When that cap is the tighter of the two, every number a pager draws would still be computed from the size
 * that was refused — the last page halved, `Next` dead with half the table unreached — so the query is
 * re-stated at the size that was served, and its links carry that one.
 */
it('re-states itself at the size it was actually served, and leaves itself alone when nothing changed', function () {
    $query = listingQuery(listingRequest(['size' => '200', 'page' => '3']), 'path');

    $served = $query->sized(25);

    expect($served->size)->toBe(25)
        ->and($served->page)->toBe(3)
        ->and($served->link())->toBe('/firefly/mappings?size=25&page=3')
        ->and($query->sized(200))->toBe($query);
});
