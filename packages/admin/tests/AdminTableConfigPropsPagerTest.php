<?php

declare(strict_types=1);

use Firefly\Admin\Tests\Support\AdminTableConfigPropsPagedCapstoneTestCase;
use Illuminate\Support\Str;

uses(AdminTableConfigPropsPagedCapstoneTestCase::class);

/**
 * The Config properties page's two listings, across a page boundary.
 *
 * AdminTableListingTest pins the qualifying and the carrying where a small fixture can reach them — the
 * header links and the two GET forms — and that is all it can see: `_pager`'s paged branch is the only
 * caller of `ListingPage::link()` anywhere, and four rows spread over two panels never render it. So the
 * rebuild `AdminAction::configPropsPage()` does, handing each `ListingPage` the CARRYING query rather than
 * the panel's own, can be deleted outright with every other assertion in the suite still green — and
 * paging Bound values then answers a URL with no `unbound_page` in it, returning the Not-bound panel to
 * page 1 while the reader is looking at the panel beside it.
 */
it('pages each config-properties panel on its own qualifier and keeps the other panel where the reader left it', function () {
    /** @var AdminTableConfigPropsPagedCapstoneTestCase $this */
    $body = (string) $this->get('/firefly/configprops?props_size=2&props_page=2&unbound_size=2&unbound_page=2')
        ->assertStatus(200)
        ->getContent();

    // Both panels reached the paged branch — without this the assertions below are vacuous, because an
    // unpaged pager draws no page links at all and `toContain` would be asserting the absence of a bug in
    // markup that was never rendered. Six bound rows over two DTOs is three pages; three unbound is two.
    expect($body)->toContain('page 2 of 3')
        ->toContain('page 2 of 2');

    // Bound values: every page link carries the Not-bound panel's size AND its page, and changes only
    // `props_page` — including the first-page jump, which drops its own page and keeps the neighbour's.
    expect($body)->toContain('<a class="act" aria-label="Page 1" aria-current="false" href="/firefly/configprops?unbound_size=2&amp;unbound_page=2&amp;props_size=2">1</a>')
        ->toContain('<a class="act on" aria-label="Page 2" aria-current="page" href="/firefly/configprops?unbound_size=2&amp;unbound_page=2&amp;props_size=2&amp;props_page=2">2</a>')
        ->toContain('<a class="act" aria-label="Page 3" aria-current="false" href="/firefly/configprops?unbound_size=2&amp;unbound_page=2&amp;props_size=2&amp;props_page=3">3</a>');

    // Not bound: the mirror, on the three profile-gated DTOs this fixture seeds.
    expect($body)->toContain('<a class="act on" aria-label="Page 2" aria-current="page" href="/firefly/configprops?props_size=2&amp;props_page=2&amp;unbound_size=2&amp;unbound_page=2">2</a>')
        ->toContain('<a class="act" aria-label="Page 1" aria-current="false" href="/firefly/configprops?props_size=2&amp;props_page=2&amp;unbound_size=2">1</a>')
        ->toMatch('~<a class="act off"\s*>Next</a>~');
});

/**
 * The sibling's SEARCH across a page boundary, which is the carrying failure with the loudest symptom: a
 * dropped `unbound_page` puts the other panel back on page 1, while a dropped `unbound_q` widens it from
 * one row back to three, so rows appear out of nowhere in a panel the reader never touched. It is also the
 * one a page-number assertion cannot catch, because the narrowed panel stops drawing page links entirely
 * and only its neighbour's links can still lose the term.
 */
it('carries the other panel search through a page link, not merely through its own form', function () {
    /** @var AdminTableConfigPropsPagedCapstoneTestCase $this */
    $body = (string) $this->get('/firefly/configprops?props_size=2&props_page=2&unbound_q=Shipping')
        ->assertStatus(200)
        ->getContent();

    expect($body)->toContain('ShippingProperties')
        ->not->toContain('ReportingProperties');

    expect($body)->toContain('<a class="act" aria-label="Page 3" aria-current="false" href="/firefly/configprops?unbound_q=Shipping&amp;props_size=2&amp;props_page=3">3</a>')
        ->toMatch('~<a class="act"\s+href="/firefly/configprops\?unbound_q=Shipping&amp;props_size=2"\s*>Previous</a>~');
});

/**
 * THE TIEBREAK, ON THE ONE LISTING THAT HAS NO UNIQUE COLUMN TO USE AS ONE.
 *
 * `InMemoryListing::page()` asks for "a key whose value is unique per row", and every other listing on this
 * dashboard hands it a column the page already draws — a route path, a bean class, a channel name. The
 * bound config properties cannot: the listing emits one row per PROPERTY across every DTO, so `class` names
 * as many rows as a DTO has properties and `key` names one per DTO that declares it — `enabled`, `store`
 * and `timeout` are declared by half the framework's own DTOs. Ordering by Property with `key` as the
 * tiebreak leaves the primary column and the tiebreak as the same non-unique key, which is a tie broken by
 * nothing at all; ordering by Value ties twice over.
 *
 * SO THIS ASSERTS THE ANSWER THE TWO CANDIDATES DISAGREE ABOUT. `audit.trail-enabled` and
 * `billing.dunning-enabled` both resolve to `true`, so the Value ordering falls through to the tiebreak for
 * exactly those two rows: the property name puts `dunningEnabled` first, and the row's identity — the class
 * that declares it, then the property — puts `AuditProperties::trailEnabled` first, because
 * `AuditProperties` precedes `BillingProperties`. Ties therefore stay GROUPED BY THE DTO instead of
 * interleaving the DTOs of an application by property name.
 */
it('breaks a tie in the bound listing by the row rather than by a property name two DTOs can share', function () {
    /** @var AdminTableConfigPropsPagedCapstoneTestCase $this */
    $body = (string) $this->get('/firefly/configprops?props_sort=value')->assertStatus(200)->getContent();

    // Anchored on the Bound values panel, so nothing here can be satisfied by the Not-bound table above it.
    $bound = Str::after($body, 'Bound values');

    expect(Str::before($bound, 'dunningEnabled'))->toContain('trailEnabled');

    // And the tie really is a tie: both rows carry the same resolved value, or the ordering above was
    // decided by the Value column and this test proves nothing about a tiebreak.
    expect($bound)->toContain('<td class="t-token" title="trailEnabled">trailEnabled</td>')
        ->toContain('<td class="t-token" title="dunningEnabled">dunningEnabled</td>')
        ->toContain('<td class="t-line" title="true">true</td>');
});
