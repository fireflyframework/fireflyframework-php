<?php

declare(strict_types=1);

use Firefly\Admin\Table\InMemoryListing;
use Firefly\Admin\Table\ListingQuery;
use Firefly\Admin\Table\TableSettings;
use Illuminate\Http\Request;

/** @return list<array<string,mixed>> */
function beanRows(): array
{
    return [
        ['class' => 'App\\Http\\OrderController', 'scope' => 'singleton', 'order' => 20],
        ['class' => 'App\\Http\\GreetingController', 'scope' => 'singleton', 'order' => 10],
        ['class' => 'App\\Jobs\\Nightly', 'scope' => 'prototype', 'order' => 30],
        ['class' => 'App\\Jobs\\Hourly', 'scope' => null, 'order' => 40],
    ];
}

/**
 * @param  array<string,mixed>  $parameters
 * @param  list<string>  $sortable
 */
function rowQuery(array $parameters = [], ?string $defaultSort = null, array $sortable = ['class', 'scope', 'order']): ListingQuery
{
    return ListingQuery::fromRequest(
        Request::create('/firefly/beans', 'GET', $parameters),
        new TableSettings(pageSize: 2, pageSizes: [2, 3]),
        '/firefly/beans',
        $sortable,
        defaultSort: $defaultSort,
    );
}

/**
 * The same rows in a shuffled arrival order, listed once per shuffle.
 *
 * @param  list<array<string,mixed>>  $rows
 * @return list<list<mixed>>
 */
function orderingsOf(array $rows, string $column, int $times = 12): array
{
    $orderings = [];
    for ($attempt = 0; $attempt < $times; $attempt++) {
        $shuffled = $rows;
        shuffle($shuffled);

        $slice = InMemoryListing::page($shuffled, rowQuery(['sort' => $column, 'size' => '3'], sortable: [$column]), [], $column);
        $orderings[] = array_column($slice->rows, $column);
    }

    return $orderings;
}

it('slices the rows to the requested page and reports the grand total', function () {
    $slice = InMemoryListing::page(beanRows(), rowQuery(['page' => '2']), ['class'], 'class');

    expect($slice->total)->toBe(4)->and($slice->rows)->toHaveCount(2)->and($slice->page)->toBe(2);
});

it('searches case-insensitively across the declared columns only', function () {
    $hit = InMemoryListing::page(beanRows(), rowQuery(['q' => 'CONTROLLER']), ['class'], 'class');
    $miss = InMemoryListing::page(beanRows(), rowQuery(['q' => 'prototype']), ['class'], 'class');

    expect($hit->total)->toBe(2)->and($miss->total)->toBe(0);
});

it('searches a column the caller did publish, and the search narrows the total, not just the page', function () {
    $slice = InMemoryListing::page(beanRows(), rowQuery(['q' => 'prototype']), ['class', 'scope'], 'class');

    expect($slice->total)->toBe(1)->and($slice->rows[0]['class'])->toBe('App\\Jobs\\Nightly');
});

it('orders by the requested column, naturally and case-insensitively', function () {
    $slice = InMemoryListing::page(beanRows(), rowQuery(['sort' => 'class', 'size' => '3']), ['class'], 'class');

    expect(array_column($slice->rows, 'class'))->toBe([
        'App\\Http\\GreetingController',
        'App\\Http\\OrderController',
        'App\\Jobs\\Hourly',
    ]);
});

it('compares numbers as numbers, not as strings', function () {
    $slice = InMemoryListing::page(
        [['n' => 9], ['n' => 100], ['n' => 20]],
        rowQuery(['sort' => 'order', 'size' => '3']),
        [],
        'n',
    );

    expect(array_column($slice->rows, 'n'))->toBe([9, 20, 100]);
});

/**
 * BOTH DIRECTIONS IS THE WHOLE CLAIM, so both are asserted. `null` and `''` render as an em-dash: they are
 * the absence of an answer, not an answer that sorts low, so they belong at the END either way. A descending
 * sort that mirrors the empty rank along with the values opens the listing on a page of em-dashes — which is
 * exactly what the rule exists to prevent, and it looks green if only the ascending half is asserted. The
 * empty row is added here rather than to `beanRows()` so the page arithmetic the other tests assert over that
 * fixture keeps saying what it says.
 */
it('sorts nulls and empty strings last in both directions', function () {
    $rows = [...beanRows(), ['class' => 'App\\Jobs\\Weekly', 'scope' => '', 'order' => 50]];

    $ascending = InMemoryListing::page($rows, rowQuery(['sort' => 'scope', 'size' => '3']), [], 'class');
    $descending = InMemoryListing::page($rows, rowQuery(['sort' => 'scope', 'dir' => 'desc', 'size' => '3']), [], 'class');
    $tail = InMemoryListing::page($rows, rowQuery(['sort' => 'scope', 'dir' => 'desc', 'page' => '2', 'size' => '3']), [], 'class');

    expect(array_column($ascending->rows, 'scope'))->toBe(['prototype', 'singleton', 'singleton'])
        ->and(array_column($descending->rows, 'scope'))->toBe(['singleton', 'singleton', 'prototype'])
        // The tiebreak stays ascending under `desc`, so the two singletons keep their class order.
        ->and(array_column($descending->rows, 'class'))->toBe([
            'App\\Http\\GreetingController',
            'App\\Http\\OrderController',
            'App\\Jobs\\Nightly',
        ])
        // …and the em-dashes are on the LAST page of the descending sort, not the first.
        ->and(array_column($tail->rows, 'class'))->toBe(['App\\Jobs\\Hourly', 'App\\Jobs\\Weekly'])
        ->and(array_column($tail->rows, 'scope'))->toBe([null, '']);
});

/**
 * THE TIEBREAK IS THE WHOLE POINT OF THIS TEST. Two rows with the same `scope` have no defined order
 * without one, so the row the storage engine — or, here, the payload — happens to emit first can differ
 * between the query for page 1 and the query for page 2: a row is then seen twice, and another never.
 * The tiebreak stays ASCENDING under a descending sort, because it is an identity, not a second ordering.
 */
it('breaks a tie on a stable secondary key, so no row is seen twice or never across pages', function () {
    $rows = [];
    foreach (range(1, 9) as $n) {
        $rows[] = ['class' => 'App\\Bean'.$n, 'scope' => 'singleton'];
    }
    shuffle($rows);

    $seen = [];
    foreach ([1, 2, 3] as $page) {
        $slice = InMemoryListing::page($rows, rowQuery(['sort' => 'scope', 'dir' => 'desc', 'page' => (string) $page, 'size' => '3']), [], 'class');
        $seen = [...$seen, ...array_column($slice->rows, 'class')];
    }

    expect($seen)->toBe(array_map(static fn (int $n): string => 'App\\Bean'.$n, range(1, 9)));
});

/**
 * ONE ORDER, WHATEVER ORDER THE PAYLOAD ARRIVED IN — the property the tiebreak above is only half of.
 *
 * A tiebreak makes the order of EQUAL rows definite; it cannot help when the comparison itself disagrees
 * with itself. Choosing arithmetic or natural comparison per PAIR does exactly that on a column that mixes
 * the two: `1.10 < 1.9` as numbers, `1.9 < 1.9-beta` as text, `1.9-beta < 1.10` as text — a cycle, so
 * `usort()` is free to answer differently for each arrival order, and these three rows really did come back
 * in three different orders across the six permutations of one payload. The payload is rebuilt per request,
 * so that is the same "a row is seen twice, and another never" across pages, arriving through the primary
 * comparison where no tiebreak can reach it. The column decides once instead, so every shuffle agrees.
 */
it('orders a column that mixes numbers and text identically however the payload arrived', function () {
    $rows = array_map(static fn (string $version): array => ['version' => $version], ['1.10', '1.9', '1.9-beta']);

    expect(orderingsOf($rows, 'version'))->each->toBe(['1.9', '1.9-beta', '1.10']);
});

/**
 * …and the column-wide decision does NOT cost a nullable numeric column its arithmetic. The empties are
 * skipped by the scan rather than counted as "not a number", because they are ranked out of the ordering
 * before any comparison sees them. Negative numbers are what makes the difference visible: `-12` precedes
 * `-3` as a number and follows it as natural text, where the digits after the sign are read on their own.
 */
it('still compares a numeric column arithmetically when some of its rows are empty', function () {
    $rows = array_map(static fn (?string $delta): array => ['delta' => $delta], ['-3', '-12', null]);

    expect(orderingsOf($rows, 'delta'))->each->toBe(['-12', '-3', null]);
});

it('orders by the tiebreak when nothing was requested and no default was declared', function () {
    $slice = InMemoryListing::page(beanRows(), rowQuery(['size' => '3']), [], 'class');

    expect($slice->rows[0]['class'])->toBe('App\\Http\\GreetingController');
});

it('honours the listing default sort when the caller declared one', function () {
    $slice = InMemoryListing::page(beanRows(), rowQuery(['size' => '3'], defaultSort: 'order'), [], 'class');

    expect($slice->rows[0]['order'])->toBe(10);
});

it('renders the last page rather than an empty one when the page is past the end', function () {
    $slice = InMemoryListing::page(beanRows(), rowQuery(['page' => '99']), [], 'class');

    expect($slice->page)->toBe(2)->and($slice->rows)->toHaveCount(2);
});

it('keeps distinct identities stable when their display comparison ties', function (string $first, string $second, string $sort) {
    $rows = [['id' => $first, 'group' => 'same'], ['id' => $second, 'group' => 'same']];
    foreach ([$rows, array_reverse($rows)] as $input) {
        $slice = InMemoryListing::page($input, rowQuery(['sort' => $sort], sortable: ['id', 'group']), [], 'id');
        expect(array_column($slice->rows, 'id'))->toBe([$first, $second]);
    }
})->with([
    ['CLIENT-A', 'client-a', 'group'],
    ['CLIENT-A', 'client-a', 'id'],
    ['01', '1', 'group'],
    ['01', '1', 'id'],
]);

it('reverses the exact identity comparison when explicitly sorting that identifier descending', function () {
    $rows = [['id' => 'CLIENT-A'], ['id' => 'client-a']];
    $slice = InMemoryListing::page($rows, rowQuery(['sort' => 'id', 'dir' => 'desc'], sortable: ['id']), [], 'id');
    expect(array_column($slice->rows, 'id'))->toBe(['client-a', 'CLIENT-A']);
});
