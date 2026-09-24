<?php

declare(strict_types=1);

use Firefly\Actuator\Health\HealthIndicator;
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

it('pages the beans catalogue on the server and types its columns', function () {
    /** @var AdminTableCapstoneTestCase $this */
    $body = (string) $this->get('/firefly/beans?size=25')->assertStatus(200)->getContent();

    expect($body)->toContain('<table class="ftable">')
        ->toContain('class="t-qual"')
        ->toContain('total</span>')
        ->not->toContain('data-filter="beans-body"');
});

it('orders the beans by a column the page draws, and refuses one it does not', function () {
    /** @var AdminTableCapstoneTestCase $this */
    $this->get('/firefly/beans?sort=scope')->assertStatus(200)->assertSee('dir=desc', false);
    $this->get('/firefly/beans?sort=secret')->assertStatus(200)->assertDontSee('sort=secret', false);
});

/**
 * The Beans page promises, in two strings it renders verbatim, that `?q=` looks inside a bean's interfaces
 * — "Search by class, stereotype or interface…" in the placeholder, and "class, stereotype, scope, name or
 * interfaces" in the empty state. A qualified interface name is the form an operator actually has to hand,
 * pasted out of the editor they came from, and it used to answer a confident "Nothing matches" while three
 * beans in the catalogue implemented exactly it: the row held only the leaf names, because the flattening
 * that made the column sortable had been applied before the row was built rather than at render time.
 *
 * The two are pinned TOGETHER — the leaf reading and the qualified one over the same term — because either
 * alone passes on a page that searches only the other. The tbody comparison is what says they are the same
 * catalogue and not merely two non-empty ones.
 */
it('narrows the beans catalogue by a qualified interface name, the way the page says it does', function () {
    /** @var AdminTableCapstoneTestCase $this */
    $leaf = (string) $this->get('/firefly/beans?q=HealthIndicator')->assertStatus(200)->getContent();
    $qualified = (string) $this->get('/firefly/beans?q='.urlencode(HealthIndicator::class))->assertStatus(200)->getContent();

    expect($qualified)->not->toContain('Nothing matches')
        ->toContain('DbHealthIndicator')
        // Still a NARROWING, not the whole catalogue back: the ten ActuatorEndpoint beans are not in it.
        ->not->toContain('MappingsEndpoint');

    expect(Str::between($qualified, '<tbody>', '</tbody>'))->toBe(Str::between($leaf, '<tbody>', '</tbody>'));

    // And the searched value is one the reader can see, which is InMemoryListing's rule for what may be in
    // `$searchable`: the cell draws the leaves and hovers the qualified list, exactly as the Class column
    // beside it has kept its FQCN on the title all along. Before this, that title repeated the cell's own
    // text back at it.
    expect($qualified)->toContain('<td class="t-text dim" title="'.HealthIndicator::class.'">HealthIndicator</td>');
});

/**
 * Two listings share the Conditions page, so each takes a qualifier — Spring's `@Qualifier("pos") Pageable`
 * in one parameter name. Paging one must not page the other, and each one's links must carry the other's
 * position or the panel a reader is not looking at silently jumps back to page 1.
 *
 * WHAT THIS FIXTURE CAN SEE IS THE QUALIFYING, and the carrying only where it rides on a header link or a
 * hidden field: nine applied conditions and two backed-off ones fit on every size the rows-per-page control
 * offers here, so `isPaged()` is false on both panels and `_pager`'s paged branch — the only caller of
 * `ListingPage::link()` anywhere — never renders. `pos_page=2` below comes back out of the Backed-off
 * panel's forms, which is the carrying rather than the paging. The page links are pinned over a fixture
 * built to page, in AdminTableConditionsPagerTest.
 */
it('qualifies each conditions panel and carries the other position through its forms and header links', function () {
    /** @var AdminTableCapstoneTestCase $this */
    $body = (string) $this->get('/firefly/conditions?pos_page=2&neg_page=3')->assertStatus(200)->getContent();

    expect($body)->toContain('pos_page=2')
        ->toContain('neg_page=3')
        ->toContain('pos_sort=class')
        ->toContain('neg_sort=class');
});

/**
 * The trigger normaliser has two halves and this pins both, because the column TYPES prove neither:
 * `class="t-num"` is the `<th>` the head partial emits from the column definition and says nothing about
 * what the cells under it hold — those render `class="t-num dim"`.
 *
 * What it keeps: all three triggers are STRINGS (`ScheduledDescriptor` types them `?string` and Cadence
 * parses the intervals through `Duration::parse()`), so `0 2 * * *` and `30s` are what the endpoint
 * publishes and what the page has to draw. A normaliser rewritten around `is_numeric()` — the obvious
 * shape, and the one the plan carried — turns every interval on the page into an em-dash without failing
 * a single assertion about a colgroup or a pager. What it drops: an absent trigger, which becomes the
 * empty string the view draws as `—`, and every row of the fixture carries exactly one trigger.
 */
it('renders the scheduled triggers the scanner publishes and an em-dash for the ones a task lacks', function () {
    /** @var AdminTableCapstoneTestCase $this */
    $body = (string) $this->get('/firefly/scheduled')->assertStatus(200)->getContent();

    expect($body)->toContain('<table class="ftable">')
        ->toContain('class="t-num"')
        ->toContain('>0 2 * * *<')
        ->toContain('>UTC<')
        ->toContain('>30s<')
        ->toContain('<td class="t-num dim">—</td>');
});

/**
 * A Number column is a RENDERING here, not an ordering. `30s`, `5m` and `1h` are duration strings, so the
 * comparison the column would get is `strnatcasecmp` and ascending by rate answers `1h, 5m, 30s, 250ms` —
 * so the page does not offer the ordering at all: no link in the two interval headers, and a hand-written
 * `?sort=` on one of them is refused like any other column the listing did not publish.
 */
it('offers no ordering by the interval columns it cannot order', function () {
    /** @var AdminTableCapstoneTestCase $this */
    $this->get('/firefly/scheduled')
        ->assertStatus(200)
        ->assertSee('sort=cron', false)
        ->assertDontSee('sort=fixedRate', false)
        ->assertDontSee('sort=fixedDelay', false);

    $this->get('/firefly/scheduled?sort=fixedRate')->assertStatus(200)->assertDontSee('sort=fixedRate', false);
});

/**
 * `firefly.observability.metrics.store` is a qualified name whose LEAF is the answer and whose stem locates
 * it, exactly like a class — which is why ColumnKind::Qualified takes its separator as a parameter instead
 * of the environment growing a fourth width mechanism of its own.
 */
it('splits a dotted configuration key the same way it splits a class name', function () {
    /** @var AdminTableCapstoneTestCase $this */
    $body = (string) $this->get('/firefly/env')->assertStatus(200)->getContent();

    expect($body)->toContain('<table class="ftable">')
        ->toContain('class="t-qual"')
        ->toContain('<span class="nm">enabled</span>')
        ->toContain('class="ns stem">firefly.management');
});

it('pages the environment and searches keys and values together', function () {
    /** @var AdminTableCapstoneTestCase $this */
    $this->get('/firefly/env?q=management')
        ->assertStatus(200)
        ->assertSee('firefly.management', false)
        ->assertDontSee('data-filter="env-body"', false);
});

/**
 * TWO LISTINGS ON ONE PAGE, the same arrangement the Conditions page has and for the same reason: what
 * bound and what did not are two questions, and a reader paging one of them must not silently reset the
 * other. The panels are qualified `props` and `unbound`, so every parameter on this page is prefixed and
 * each panel's links carry the other's position.
 *
 * ONE ROW PER PROPERTY, NOT PER DTO, is the other claim here and the fixture is built to show it: the
 * bound DTO carries three properties, so `3 total` is the bound panel's count while the manifest declares
 * a single bound class. A table of one row per class with a blob of values in a cell reads the same in a
 * screenshot and cannot be searched by key, which is how someone actually looks a value up.
 */
it('pages the bound config properties and the unbound ones independently', function () {
    /** @var AdminTableCapstoneTestCase $this */
    $body = (string) $this->get('/firefly/configprops?props_page=1')->assertStatus(200)->getContent();

    expect($body)->toContain('props_sort=')->toContain('Bound values')
        // Both panels rendered as listings, each with its own qualified search input.
        ->toContain('Not bound')
        ->toContain('name="props_q"')
        ->toContain('name="unbound_q"')
        // One row per property of the one bound DTO, and the reason the other one did not bind.
        ->toContain('>dailyTransferLimitMinor<')
        ->toContain('>dunningEnabled<')
        ->toContain('>250000<')
        ->toContain('Requires the production profile, which is not active.')
        ->toContain('3 total');
});

// Each panel carries the OTHER's position, so paging or ordering one leaves the reader where they were in
// the one they are not looking at.
it('carries each config-properties panel\'s position through the other panel\'s links', function () {
    /** @var AdminTableCapstoneTestCase $this */
    $body = (string) $this->get('/firefly/configprops?props_sort=key&unbound_sort=prefix')
        ->assertStatus(200)
        ->getContent();

    expect($body)->toContain('props_sort=key')->toContain('unbound_sort=prefix');
});

it('pages the cache stores and the log channels', function () {
    /** @var AdminTableCapstoneTestCase $this */
    $this->get('/firefly/caches')->assertStatus(200)->assertSee('<table class="ftable">', false);
    $this->get('/firefly/loggers')->assertStatus(200)->assertSee('<table class="ftable">', false);
});

// The level control is a POST form inside the listing, so the column it lives in shrinks to it rather than
// carrying the `style="width:1%"` the view used to hand-write.
it('gives the logger level control its own actions column instead of an inline width', function () {
    /** @var AdminTableCapstoneTestCase $this */
    $body = (string) $this->get('/firefly/loggers')->assertStatus(200)->getContent();

    expect($body)->toContain('class="t-actions"')->not->toContain('style="width:1%"');
});
