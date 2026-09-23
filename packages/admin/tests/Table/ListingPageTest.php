<?php

declare(strict_types=1);

use Firefly\Admin\Table\ListingPage;
use Firefly\Admin\Table\ListingQuery;
use Firefly\Admin\Table\TableSettings;
use Illuminate\Http\Request;

function pageQuery(int $page = 1, int $size = 25): ListingQuery
{
    return new ListingQuery(new TableSettings(pageSize: $size, pageSizes: [$size]), '/firefly/beans', $page, $size, null, 'asc', null);
}

it('reports the range it is showing, one-based and inclusive', function () {
    $slice = ListingPage::sliced(array_fill(0, 25, 'row'), 207, pageQuery(page: 3));

    expect($slice->page)->toBe(3)
        ->and($slice->from())->toBe(51)
        ->and($slice->to())->toBe(75)
        ->and($slice->lastPage())->toBe(9)
        ->and($slice->hasPrevious())->toBeTrue()
        ->and($slice->hasNext())->toBeTrue()
        ->and($slice->isPaged())->toBeTrue();
});

it('shows 0 as the start of an empty listing rather than 1', function () {
    $slice = ListingPage::sliced([], 0, pageQuery());

    expect($slice->from())->toBe(0)->and($slice->to())->toBe(0)->and($slice->isEmpty())->toBeTrue()
        ->and($slice->lastPage())->toBe(1)->and($slice->isPaged())->toBeFalse();
});

// The EFFECTIVE page, so the pager's Previous link is one back from the page that was RENDERED and not one
// back from the page that was asked for.
it('clamps a page past the end onto the last one', function () {
    $slice = ListingPage::sliced(array_fill(0, 7, 'row'), 207, pageQuery(page: 999));

    expect($slice->page)->toBe(9)->and($slice->hasNext())->toBeFalse()->and($slice->hasPrevious())->toBeTrue();
});

it('windows the page numbers around the current one and keeps both ends reachable', function () {
    expect(ListingPage::sliced([], 207, pageQuery(page: 5))->window())->toBe([3, 4, 5, 6, 7])
        ->and(ListingPage::sliced([], 207, pageQuery(page: 1))->window())->toBe([1, 2, 3])
        ->and(ListingPage::sliced([], 207, pageQuery(page: 9))->window())->toBe([7, 8, 9]);
});

it('builds a page link through the query it came from', function () {
    $query = ListingQuery::fromRequest(
        Request::create('/firefly/beans', 'GET', ['q' => 'filter']),
        new TableSettings,
        '/firefly/beans',
        ['class'],
    );

    expect(ListingPage::sliced([], 400, $query)->link(3))->toBe('/firefly/beans?q=filter&page=3');
});
