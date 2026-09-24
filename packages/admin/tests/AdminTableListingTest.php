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

/**
 * Each panel carries the OTHER's position, so paging or ordering one leaves the reader where they were in
 * the one they are not looking at.
 *
 * IT IS THE `page` PARAMETER THAT MAKES THIS ASSERTION MEAN ANYTHING, for the same reason the conditions
 * test above uses it. Asking for `?props_sort=key&unbound_sort=prefix` and looking for those two strings
 * proves nothing at all: `props_sort=key` is written by the Bound panel's OWN `key` header — `sortLink()`
 * emits it because `key` is not that listing's default sort — and by its own search form's hidden fields,
 * and `unbound_sort=prefix` by the Not-bound panel's own, so both substrings are in the body whether or
 * not either query carries the other. A panel's own links can never write its own `page`: `hiddenFields()`
 * omits it, `sortLink()` nulls it, and `_pager`'s paged branch does not render for a fixture that fits on
 * one page. So `props_page=2` in this body can only have arrived through the Not-bound panel's carried
 * state, and `unbound_page=3` through the Bound panel's.
 *
 * `ListingQuery::fromRequest()` keeps the REQUESTED page — it cannot know the total — and `meaningful()`
 * writes it because it is not 1, while `ListingPage::sliced()` still clamps what is rendered; that is what
 * lets a one-page fixture carry a page number at all. The pager's own links, which a one-page fixture
 * cannot draw, are pinned over a fixture built to page in AdminTableConfigPropsPagerTest.
 */
it('carries each config-properties panel\'s position through the other panel\'s links', function () {
    /** @var AdminTableCapstoneTestCase $this */
    $body = (string) $this->get('/firefly/configprops?props_page=2&unbound_page=3')
        ->assertStatus(200)
        ->getContent();

    expect($body)->toContain('props_page=2')->toContain('unbound_page=3');
});

/**
 * THE ONE LISTING THAT DOES CLAIM AN ORDERING, AND THE REASON IT IS ALLOWED TO. The Bound panel's tiebreak
 * is `row` — the synthetic `Class::property` identity that gives one row per property something unique to
 * be ordered by — and `row` is drawn by no column, so a null default sort would leave the table ordered by
 * a key no header can name and no reader can click back to. It declares `class`, the drawn column that
 * ordering already amounts to, and the arrow on Class is then true.
 *
 * ITS NEIGHBOUR ON THE SAME PAGE DECLARES NOTHING, because its tiebreak IS its Class column: the Not-bound
 * panel opens ordered by class with every header link naming its own column, exactly like Routes, Beans
 * and the Environment. The two panels differ because their ORDERINGS differ in kind, not because two
 * authors made two choices — which is the distinction this pins.
 */
it('claims an ordering on the bound properties panel alone, whose tiebreak no column draws', function () {
    /** @var AdminTableCapstoneTestCase $this */
    $body = (string) $this->get('/firefly/configprops')->assertStatus(200)->getContent();

    expect($body)->toContain('<a href="/firefly/configprops?props_dir=desc">Class<span class="ord">↑</span></a>')
        ->toContain('<a href="/firefly/configprops?unbound_sort=class">Class<span class="ord"></span></a>');
});

it('pages the cache stores and the log channels', function () {
    /** @var AdminTableCapstoneTestCase $this */
    $this->get('/firefly/caches')->assertStatus(200)->assertSee('<table class="ftable">', false);
    $this->get('/firefly/loggers')->assertStatus(200)->assertSee('<table class="ftable">', false);
});

/**
 * ONE ARROW MEANS ONE THING ON EVERY PAGE OF THIS DASHBOARD, and these four are the ones that nearly
 * shipped the other reading. `listing()` leaves `$defaultSort` null for a listing whose natural order is
 * already its tiebreak, so the Environment table opens ordered by Key with no arrow on it and a header
 * link that names its own column — the same thing `/firefly/mappings` does under "sorts on the server"
 * above, and the same thing the Caches and Loggers tables do here.
 *
 * WHAT DECLARING `defaultSort: 'key'` WOULD CHANGE IS THE PAGE, NOT THE LISTING. InMemoryListing falls
 * back to the tiebreak when no sort was asked for, so the rows are identical either way — the second half
 * of this test reads both bodies and says so. `meaningful()` then omits a parameter already at its
 * default, which turns that one header link into a bare `?dir=desc` and hands it an `↑` before the reader
 * has asked for anything; the neighbouring columns keep their `?sort=`. That is a defensible convention
 * and the opposite one is too, but not both in one dashboard: Configuration pages claiming an ordering
 * and Routes not claiming the same ordering is one affordance answering the same question two ways.
 */
it('claims no ordering on a configuration listing the reader has not ordered', function () {
    /** @var AdminTableCapstoneTestCase $this */
    $opening = [
        '/firefly/env' => '<a href="/firefly/env?sort=key">Key<span class="ord"></span></a>',
        '/firefly/caches' => '<a href="/firefly/caches?sort=name">Store<span class="ord"></span></a>',
        '/firefly/loggers' => '<a href="/firefly/loggers?sort=name">Channel<span class="ord"></span></a>',
    ];

    foreach ($opening as $url => $header) {
        $body = (string) $this->get($url)->assertStatus(200)->getContent();

        expect($body)->toContain($header)
            // Not "no arrow on that column" but no arrow anywhere: the indicator is the page's one claim
            // about its own ordering, and an unordered listing makes none.
            ->not->toContain('<span class="ord">↑');
    }

    // The arrow is what a reader ASKED for, and asking for the order the table is already in reorders
    // nothing — which is precisely why the default had no business being declared.
    $rowsOf = static function (string $body): string {
        preg_match('#<tbody>(.*?)</tbody>#s', $body, $matches);

        return $matches[1] ?? '';
    };

    $asked = (string) $this->get('/firefly/env?sort=key')->assertStatus(200)->getContent();
    $opened = (string) $this->get('/firefly/env')->assertStatus(200)->getContent();

    expect($asked)->toContain('<a href="/firefly/env?sort=key&amp;dir=desc">Key<span class="ord">↑</span></a>')
        ->and($rowsOf($asked))->not->toBe('')
        ->and($rowsOf($asked))->toBe($rowsOf($opened));
});

// The level control is a POST form inside the listing, so the column it lives in shrinks to it rather than
// carrying the `style="width:1%"` the view used to hand-write.
it('gives the logger level control its own actions column instead of an inline width', function () {
    /** @var AdminTableCapstoneTestCase $this */
    $body = (string) $this->get('/firefly/loggers')->assertStatus(200)->getContent();

    expect($body)->toContain('class="t-actions"')->not->toContain('style="width:1%"');
});

/**
 * APPLYING A LEVEL MUST NOT COST THE READER THEIR PLACE, which it did not have to consider until this page
 * became a listing: every channel was on one page, so a redirect to the bare URL lost nothing. Now the same
 * redirect answers the unfiltered first page, and someone who searched for `err` on page 2 has to find
 * their channel again — the state is in the URL and a POST does not carry a URL.
 *
 * The form's own field is asserted as well as the redirect, because either half alone passes on a broken
 * page: an action that honours `back` is unreachable if the form never sends one.
 */
it('returns the reader to the search and page they applied a logger level from', function () {
    /** @var AdminTableCapstoneTestCase $this */
    $body = (string) $this->get('/firefly/loggers?q=err&sort=level&dir=desc')->assertStatus(200)->getContent();

    expect($body)->toContain('name="back" value="/firefly/loggers?q=err&amp;sort=level&amp;dir=desc"');

    $this->post('/firefly/loggers', ['logger' => 'errorlog', 'level' => 'DEBUG', 'back' => '/firefly/loggers?q=err&sort=level&dir=desc'])
        ->assertRedirect('/firefly/loggers?q=err&sort=level&dir=desc');
});

/**
 * …and `back` is a form field, so it is caller-supplied. A value that is not this page — another dashboard
 * page, or the protocol-relative `//elsewhere` that a naive prefix check reads as a path — is dropped
 * rather than followed, which is what keeps an admin form from being an open redirect.
 */
it('refuses to redirect anywhere but the loggers page after applying a level', function (string $forged) {
    /** @var AdminTableCapstoneTestCase $this */
    $this->post('/firefly/loggers', ['logger' => 'errorlog', 'level' => 'DEBUG', 'back' => $forged])
        ->assertRedirect('/firefly/loggers');
})->with([
    // A protocol-relative URL, which a bare `str_starts_with('/')` check reads as a path.
    '//example.com/',
    // The prefix check has to end at a boundary, or this page's own URL is a prefix of another one.
    '/firefly/loggers-elsewhere',
    'https://example.com/firefly/loggers',
    '/firefly/env',
]);

/**
 * THE LISTING UNIT ON THE METRICS PAGE IS THE METER, NOT THE MEASUREMENT. A meter's `COUNT` and its
 * `TOTAL_TIME` are two readings of one thing, so a page that sliced by measurement could put the count on
 * page 3 and the total it counts on page 4. The page therefore sorts, searches and slices METERS, and each
 * meter draws however many rows it has — which is also why the fixture's `http.server.requests` carries two
 * measurements: on a per-measurement listing they are two rows that can be separated, and on this one they
 * cannot be.
 *
 * `style="width:22%"` is the hand-written column width the Relative bar used to carry, and the assertion
 * that it is gone is the assertion that this table is laid out by the vocabulary rather than by one number
 * somebody typed into the markup.
 */
it('pages the metrics by meter and keeps every statistic of a meter on one page', function () {
    /** @var AdminTableCapstoneTestCase $this */
    $body = (string) $this->get('/firefly/metrics?size=25')->assertStatus(200)->getContent();

    expect($body)->toContain('<table class="ftable">')
        ->toContain('class="t-meter"')
        ->not->toContain('style="width:22%"');

    // The two measurements of one meter, in one <tbody>, with the meter named once and its second row
    // carrying an empty name cell — the shape that says "these belong together".
    preg_match('#<tbody[^>]*>(.*?)</tbody>#s', $body, $rows);
    expect($rows[1] ?? '')->toContain('COUNT')->toContain('TOTAL_TIME')
        // A meter the registry holds under a name with no measurements is legal, and the view has an arm
        // for it rather than a missing row.
        ->toContain('no measurements');
});

/**
 * THE HTTP LISTING IS THE ONE THAT OPENS ON AN ORDER NOBODY ASKED FOR, and is allowed to.
 *
 * Every other listing on this dashboard opens in its tiebreak's order and claims nothing (see "claims no
 * ordering on a configuration listing the reader has not ordered"). This one's tiebreak is the correlation
 * id and its opening order is newest-first — a deliberate choice about a log, not an identity — so it
 * declares `timestamp desc` and `meaningful()` keeps that pair out of every URL it writes: the landing page
 * is `/firefly/http`, and the When header's own link degrades to a bare `?dir=asc`.
 *
 * The Method link carries `dir=asc` EXPLICITLY, and that is not noise: this listing's declared default
 * direction is `desc`, so ascending is the half of the pair that differs from the default and has to be
 * written down. A link that omitted it would sort by method descending.
 */
it('opens the HTTP traffic newest first, and says so without putting it in the URL', function () {
    /** @var AdminTableCapstoneTestCase $this */
    $this->get('/firefly/http')
        ->assertStatus(200)
        ->assertSee('href="/firefly/http?sort=method&amp;dir=asc"', false)
        // The default sort is elided from every link, so the landing URL stays /firefly/http and the When
        // column's own header link asks only for the other direction.
        ->assertSee('href="/firefly/http?dir=asc"', false)
        ->assertDontSee('href="/firefly/http?sort=timestamp&amp;dir=desc"', false);
});

it('flips the HTTP sort to oldest first when the reader asks', function () {
    /** @var AdminTableCapstoneTestCase $this */
    $body = (string) $this->get('/firefly/http?dir=asc')->assertStatus(200)->getContent();

    expect($body)->toContain('<span class="ord">↑</span>');

    // Oldest first really is oldest first: the 500 the fixture recorded two hours ago leads, and the 200 it
    // recorded two seconds ago is behind it.
    preg_match('#<tbody[^>]*>(.*?)</tbody>#s', $body, $rows);
    expect(strpos($rows[1] ?? '', 'code err'))->toBeInt()
        ->toBeLessThan((int) strpos($rows[1] ?? '', 'code ok'));
});

it('pages the OAuth2 clients and keeps the process-local caveat', function () {
    /** @var AdminTableCapstoneTestCase $this */
    $this->get('/firefly/oauth2')
        ->assertStatus(200)
        ->assertSee('<table class="ftable">', false)
        ->assertSee('href="/firefly/oauth2?sort=clientId"', false)
        // The client id over the client name is a two-line cell like a class name — but WITHOUT `stem`:
        // a human name is prose and elides from the right, not from the left.
        ->assertSee('<span class="ns">Storefront</span>', false)
        ->assertDontSee('<span class="ns stem">Storefront</span>', false)
        // The Active column's `—` for a zero no per-process store can vouch for, and the paragraph naming
        // the key that makes the column server-wide, both survive the move onto the listing engine.
        ->assertSee('<td class="t-num">—</td>', false)
        ->assertSee('Active counts this worker only');
});

/**
 * THE THREE RUNTIME PAGES ANSWER "WHAT IS THIS SORTED BY?" THE WAY THE REST OF THE DASHBOARD DOES.
 *
 * Metrics and OAuth2 clients open in their tiebreak's order — the meter name, the client id — so they
 * claim nothing: every header link names its own column and no arrow is drawn until a reader asks. HTTP
 * traffic is the single exception on this dashboard and it is asserted above. Getting this wrong is not a
 * broken page, which is exactly why it needs pinning: it is one affordance quietly meaning two things
 * depending on which page you are on.
 */
it('claims no ordering on the runtime listings the reader has not ordered', function () {
    /** @var AdminTableCapstoneTestCase $this */
    $opening = [
        '/firefly/metrics' => '<a href="/firefly/metrics?sort=name">Meter<span class="ord"></span></a>',
        '/firefly/oauth2' => '<a href="/firefly/oauth2?sort=clientId">Client<span class="ord"></span></a>',
    ];

    foreach ($opening as $url => $header) {
        $body = (string) $this->get($url)->assertStatus(200)->getContent();

        expect($body)->toContain($header)->not->toContain('<span class="ord">↑');
    }
});

/**
 * The Relative bar is a picture of the WHOLE result set, not of the slice the reader is looking at.
 *
 * A bar rescaled per page would say something different about the same number depending on which page it
 * was drawn on — 100% on page 2 because page 2's largest meter is small. The scale is therefore the peak
 * across every meter the endpoint reported, computed once and handed to the view, which is why the page's
 * widest value renders as a full bar even when the listing is narrowed to something smaller.
 */
it('scales the metrics bar against every meter, not against the page being drawn', function () {
    /** @var AdminTableCapstoneTestCase $this */
    $narrowed = (string) $this->get('/firefly/metrics?q=cache')->assertStatus(200)->getContent();

    // `firefly.cache.hits` is 512 against a fixture peak of 2 097 152, so on its own page it is a sliver
    // rather than the 100% a per-slice scale would give it.
    expect($narrowed)->toContain('<i style="width:0.02%"></i>')
        ->not->toContain('<i style="width:100%"></i>');
});
