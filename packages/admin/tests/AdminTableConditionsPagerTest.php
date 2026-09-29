<?php

declare(strict_types=1);

use Firefly\Admin\Tests\Support\AdminTableConditionsPagedCapstoneTestCase;

uses(AdminTableConditionsPagedCapstoneTestCase::class);

/**
 * Two listings on one page, each paging on its own qualifier and each carrying the other's position.
 *
 * AdminTableListingTest pins the qualifying and the carrying where they can be reached without a page
 * boundary — the header links and the two GET forms — and that is the half its fixture can see. This is the
 * other half, and it is the one the mechanism was written for: `AdminAction::conditionsPage()` rebuilds each
 * `ListingPage` on the CARRYING query rather than on the panel's own, because `ListingPage::link()` is the
 * only thing that reads the query a page was built with and it is called from exactly one place —
 * `_pager`'s paged branch. Eleven rows over two panels never render that branch, so the rebuild can be
 * deleted outright with every other assertion in the suite still green, and paging Applied then answers a
 * URL with no `neg_page` in it: the Backed-off panel silently returns to page 1 while the reader is looking
 * at the panel beside it.
 *
 * WHAT IS ASSERTED IS THE HREF, not the page count on the Applied side. Nine conditions apply in an
 * auto-configured testbench today and five pages is what two-a-page makes of them, but that number belongs
 * to the framework's own auto-configuration rather than to this fixture; the backed-off side, which this
 * case seeds, is pinned exactly.
 */
it('pages each conditions panel on its own qualifier and keeps the other panel where the reader left it', function () {
    /** @var AdminTableConditionsPagedCapstoneTestCase $this */
    $body = (string) $this->get('/firefly/conditions?pos_size=2&pos_page=2&neg_size=2&neg_page=3')
        ->assertStatus(200)
        ->getContent();

    // Both panels reached the paged branch — without this the three assertions below are vacuous, because
    // an unpaged pager draws no links at all and `toContain` would be asserting the absence of a bug in
    // markup that was never rendered.
    expect($body)->toContain('page 2 of')
        ->toContain('page 3 of 3');

    // Applied: every page link carries the Backed-off panel's size AND its page, and changes only `pos_page`.
    expect($body)->toMatch('~<a class="act"\s+href="/firefly/conditions\?neg_size=2&amp;neg_page=3&amp;pos_size=2"\s*>Previous</a>~')
        ->toContain('<a class="act on" aria-label="Page 2" aria-current="page" href="/firefly/conditions?neg_size=2&amp;neg_page=3&amp;pos_size=2&amp;pos_page=2">2</a>')
        ->toContain('<a class="act" aria-label="Page 3" aria-current="false" href="/firefly/conditions?neg_size=2&amp;neg_page=3&amp;pos_size=2&amp;pos_page=3">3</a>');

    // Backed off: the mirror, on the fixture's own five rows — three pages, the third of them current, and
    // a first-page jump that drops its own `neg_page` while keeping the neighbour's `pos_page` whole.
    expect($body)->toContain('<a class="act on" aria-label="Page 3" aria-current="page" href="/firefly/conditions?pos_size=2&amp;pos_page=2&amp;neg_size=2&amp;neg_page=3">3</a>')
        ->toContain('<a class="act" aria-label="Page 1" aria-current="false" href="/firefly/conditions?pos_size=2&amp;pos_page=2&amp;neg_size=2">1</a>')
        ->toMatch('~<a class="act"\s+href="/firefly/conditions\?pos_size=2&amp;pos_page=2&amp;neg_size=2&amp;neg_page=2"\s*>Previous</a>~')
        ->toMatch('~<a class="act off"\s*>Next</a>~');
});

/**
 * The sibling's SEARCH across a page boundary, which is the carrying failure with the loudest symptom.
 *
 * A dropped `neg_page` puts the other panel back on page 1; a dropped `neg_q` widens it from one row back
 * to five, so rows appear out of nowhere in a panel the reader never touched — the same class of bug as a
 * page link that forgets `?q=`, one listing over. It is also the one a page-number assertion cannot catch:
 * `neg_q` narrows the Backed-off panel to a single page, so its own pager stops drawing links entirely and
 * only the Applied panel's links can still lose it.
 */
it('carries the other panel search through a page link, not merely through its own form', function () {
    /** @var AdminTableConditionsPagedCapstoneTestCase $this */
    $body = (string) $this->get('/firefly/conditions?pos_size=2&pos_page=2&neg_q=Redis')
        ->assertStatus(200)
        ->getContent();

    // One backed-off row matches, out of the five this fixture seeds. Anchored on the element, because a
    // bare `1 total` is a substring of the Applied panel's own count the day a testbench applies 21.
    expect($body)->toContain('<span class="meta">1 total</span>')
        ->toContain('RedisCacheAutoConfiguration')
        ->not->toContain('WidgetAutoConfiguration');

    expect($body)->toContain('<a class="act" aria-label="Page 3" aria-current="false" href="/firefly/conditions?neg_q=Redis&amp;pos_size=2&amp;pos_page=3">3</a>')
        ->toMatch('~<a class="act"\s+href="/firefly/conditions\?neg_q=Redis&amp;pos_size=2"\s*>Previous</a>~');
});
