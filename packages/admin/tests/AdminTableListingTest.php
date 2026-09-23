<?php

declare(strict_types=1);

use Firefly\Admin\Tests\Support\AdminTableCapstoneTestCase;
use Illuminate\Support\Str;

uses(AdminTableCapstoneTestCase::class);

it('lays the Routes table out with an explicit colgroup instead of leaving it to the content', function () {
    /** @var AdminTableCapstoneTestCase $this */
    $body = (string) $this->get('/firefly/mappings')->assertStatus(200)->getContent();

    expect($body)->toContain('<table class="ftable">')
        ->toContain('<colgroup>')
        // The pill column's width carries BOTH cell paddings, because box-sizing is border-box here and a
        // bare 7.5ch is 7.5 characters minus 28px — which is where DELETE went.
        ->toContain('<col style="width:calc(7.5ch + 2 * var(--row-x))">')
        // The three flexible columns are bare percentages — 5/4/3 of the weight, not a `calc()` that
        // subtracts the pill column. A `<col>` width mixing a percentage with a subtracted length is not
        // resolvable under `table-layout:fixed` and Chromium silently sizes the column `auto` instead,
        // which is the equal-thirds layout this whole colgroup exists to replace.
        ->toContain('<col style="width:41.6667%">')
        ->toContain('<col style="width:33.3333%">')
        ->toContain('<col style="width:25%">');
});

it('types every cell of the Routes table by the vocabulary', function () {
    /** @var AdminTableCapstoneTestCase $this */
    $body = (string) $this->get('/firefly/mappings')->assertStatus(200)->getContent();

    expect($body)->toContain('class="t-pill"')
        ->toContain('class="t-path"')
        ->toContain('class="t-qual"')
        ->toContain('class="t-token"')
        // A path is discriminated by its head, so nothing about it is elided from the left.
        ->not->toContain('class="ns stem"><span');
});

it('sorts on the server, from a link that carries the rest of the state', function () {
    /** @var AdminTableCapstoneTestCase $this */
    $this->get('/firefly/mappings')->assertSee('href="/firefly/mappings?sort=path"', false);

    $this->get('/firefly/mappings?sort=path&q=orders')
        ->assertStatus(200)
        ->assertSee('href="/firefly/mappings?q=orders&amp;sort=path&amp;dir=desc"', false);
});

// The complaint under the complaint: the filter was a keyup handler over rows that were all already in the
// response. It is a GET form now, and the narrowing is a fact about the application rather than the DOM.
it('searches on the server and says how many rows matched', function () {
    /** @var AdminTableCapstoneTestCase $this */
    // The unnarrowed reading first, so the narrowed one below is a CHANGE and not a number that happens to
    // be right. `_panel-head` prints this label out of `$count`, which mappings.blade.php feeds from
    // `$slice->total` — the size of the whole result set, not of the page. Seven rows fit on one page, so
    // the two are the same number here and this pair cannot tell a total from a row count; the fixture that
    // can is in AdminTablePagerTest, under "counts the whole result set in the header".
    $this->get('/firefly/mappings')->assertStatus(200)->assertSee('7 total', false);

    $this->get('/firefly/mappings?q=orders')
        ->assertStatus(200)
        ->assertSee('name="q"', false)
        ->assertSee('5 total', false)
        ->assertDontSee('data-filter="map-body"', false)
        // The absent row is named by its HANDLER, not by its path: the sheet is inline on this page and
        // the comment above `.wrap` quotes `/greetings/{name}` as the bug it exists to fix, so asserting
        // the raw path absent would be asserting something about a CSS comment.
        ->assertDontSee('GreetingController', false);
});

/**
 * Both GET forms on a listing, and the state neither of their own controls owns.
 *
 * The search box owns `q` and the rows-per-page select owns `size`; everything else — the ordering, and for
 * the search form the size too — rides along as a hidden input or is lost the moment either form is
 * submitted. The ordering is the one that reads as a broken feature rather than a reset: sort by path, then
 * type a term, and the matching rows come back in the order they were in before the sort link was ever
 * clicked, which looks like sorting does not work. It is the same class of bug as a link that drops `q`,
 * one mechanism over, and deleting the `hiddenFields()` loop out of either form passes every other
 * assertion in this suite — so it is asserted here against both of them at once.
 */
it('carries the ordering through the search form and the rows-per-page form', function () {
    /** @var AdminTableCapstoneTestCase $this */
    $body = (string) $this->get('/firefly/mappings?sort=path&dir=desc')->assertStatus(200)->getContent();

    expect(Str::betweenFirst($body, 'role="search"', '</form>'))
        ->toContain('<input type="hidden" name="sort" value="path">')
        ->toContain('<input type="hidden" name="dir" value="desc">')
        // `q` is NOT hidden here — the search input is the control that owns it, and a hidden field of the
        // same name would submit ahead of whatever the reader typed.
        ->toContain('type="search" name="q"');

    expect(Str::betweenFirst($body, '<div class="pager">', '</form>'))
        ->toContain('<input type="hidden" name="sort" value="path">')
        ->toContain('<input type="hidden" name="dir" value="desc">');
});

it('renders one page at a time and a pager that carries the search', function () {
    /** @var AdminTableCapstoneTestCase $this */
    $this->get('/firefly/mappings?size=25&page=1')
        ->assertStatus(200)
        // The rows-per-page control must work with scripts off: it had onchange="this.form.submit()" and
        // no button at all, which is a control that silently does nothing for a keyboard or a text browser.
        ->assertSee('Rows', false)
        ->assertSee('type="submit"', false);
});

// `assertSee('Mappings')` proves NOTHING here, which is worth writing down: _panel-head prints the panel's
// title on every branch, including the empty one, so that assertion holds whether or not the clamp works.
// What tells a clamped request from an unclamped one is that array_slice() past the end returns no rows at
// all — drop ListingPage::pageFor() from InMemoryListing::page() and this renders the empty state, with no
// rows and no pager. (Which page it landed ON needs a listing with more than one; see AdminTablePagerTest.)
it('clamps a page past the end onto the last one rather than rendering an empty table', function () {
    /** @var AdminTableCapstoneTestCase $this */
    $this->get('/firefly/mappings?page=9999&size=25')
        ->assertStatus(200)
        ->assertSee('OrderController', false)
        ->assertSee('1–7 of 7', false)
        ->assertDontSee('No routes mapped', false);
});

it('says nothing matches, and offers a way back, when a search empties the listing', function () {
    /** @var AdminTableCapstoneTestCase $this */
    $this->get('/firefly/mappings?q=zzzzzzzz')
        ->assertStatus(200)
        ->assertSee('Nothing matches', false)
        ->assertSee('href="/firefly/mappings"', false);
});
