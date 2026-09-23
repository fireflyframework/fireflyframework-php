<?php

declare(strict_types=1);

use Firefly\Admin\Tests\Support\AdminTableCapstoneTestCase;

uses(AdminTableCapstoneTestCase::class);

it('lays the Routes table out with an explicit colgroup instead of leaving it to the content', function () {
    /** @var AdminTableCapstoneTestCase $this */
    $body = (string) $this->get('/firefly/mappings')->assertStatus(200)->getContent();

    expect($body)->toContain('<table class="ftable">')
        ->toContain('<colgroup>')
        // The pill column's width carries BOTH cell paddings, because box-sizing is border-box here and a
        // bare 7.5ch is 7.5 characters minus 28px — which is where DELETE went.
        ->toContain('<col style="width:calc(7.5ch + 2 * var(--row-x))">')
        ->toContain('calc((100% - (7.5ch + 1 * 2 * var(--row-x)))');
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
    $this->get('/firefly/mappings?q=orders')
        ->assertStatus(200)
        ->assertSee('name="q"', false)
        ->assertDontSee('data-filter="map-body"', false)
        // The absent row is named by its HANDLER, not by its path: the sheet is inline on this page and
        // the comment above `.wrap` quotes `/greetings/{name}` as the bug it exists to fix, so asserting
        // the raw path absent would be asserting something about a CSS comment.
        ->assertDontSee('GreetingController', false);
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
