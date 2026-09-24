<?php

declare(strict_types=1);

use Firefly\Admin\Tests\Support\DataBrowserTestCase;
use Illuminate\Support\Facades\DB;

uses(DataBrowserTestCase::class);

/**
 * The dashboard's half of the data browser: routing, the nav entry, and that the page renders what the
 * backend returns. The backend's own gates are proven in packages/admin/tests/Data/; these prove the UI
 * cannot reach past them.
 */
it('offers the data browser in the menu when it is switched on', function () {
    /** @var DataBrowserTestCase $this */
    $this->get('/firefly')->assertStatus(200)->assertSee('Browse data', false);
});

it('serves the resource index', function () {
    /** @var DataBrowserTestCase $this */
    $this->get('/firefly/data')->assertStatus(200)->assertSee('Resources', false);
});

// A slug nothing declared must not render a broken listing.
it('answers a listing for an unknown resource without leaking a stack trace', function () {
    /** @var DataBrowserTestCase $this */
    $response = $this->get('/firefly/data?resource=nope');

    $response->assertStatus(200);
    expect($response->getContent())->not->toContain('Stack trace');
});

it('404s a record on an unknown resource', function () {
    /** @var DataBrowserTestCase $this */
    $this->get('/firefly/data?resource=nope&id=1')->assertStatus(404);
});

/**
 * The outcome sentence of a write. AdminAction::redirect() flashes it into the session for the page it
 * redirects to, and the views print it — which held on paper only: the guard resolved `session` (the
 * MANAGER) and asked whether it was a Store, so it was false on every request and the operator saw a
 * silent redirect whether the write landed or was refused.
 */
it('flashes the outcome of a write onto the page it redirects to', function () {
    /** @var DataBrowserTestCase $this */
    $this->exposeAdminRecords();

    $this->post('/firefly/data', ['resource' => 'admin-record', 'id' => '1', 'op' => 'update', 'f' => ['email' => 'changed@example.test']])
        ->assertRedirect('/firefly/data?resource=admin-record&id=1')
        ->assertSessionHas('data-message', 'Updated 1 field(s).');

    $this->get('/firefly/data?resource=admin-record&id=1')
        ->assertStatus(200)
        ->assertSee('Updated 1 field(s).', false)
        ->assertSee('changed@example.test', false);
});

it('flashes a refusal too, so a write that did not land says so on the form', function () {
    /** @var DataBrowserTestCase $this */
    $this->exposeAdminRecords();

    $this->post('/firefly/data', ['resource' => 'admin-record', 'op' => 'create', 'f' => ['email' => 'x@example.test', 'amount' => 'lots']])
        ->assertRedirect('/firefly/data?resource=admin-record&new=1')
        ->assertSessionHas('data-message', 'The value for `amount` is not a valid int.');

    $this->get('/firefly/data?resource=admin-record&new=1')
        ->assertStatus(200)
        ->assertSee('The value for `amount` is not a valid int.', false);
});

/**
 * A NOT NULL column the database defaults is not required of the person filling the form: the form says
 * `optional` for it, and a blank submission leaves it to the schema. Before, the form said `required`
 * (nullability was the only thing it looked at) and the create was refused as "not a valid bool" — a row
 * that could not be created without typing a value the database was going to supply anyway.
 */
it('marks a defaulted column optional on the new-record form, and creates the row without it', function () {
    /** @var DataBrowserTestCase $this */
    $this->exposeAdminRecords();

    $form = $this->get('/firefly/data?resource=admin-record&new=1');
    $form->assertStatus(200);

    $html = (string) $form->getContent();
    expect($html)->toMatch('/name="f\[amount\]"[^>]*placeholder="required"/')
        ->toMatch('/name="f\[active\]"[^>]*placeholder="optional"/')
        ->toMatch('/name="f\[meta\]"[^>]*placeholder="optional"/');

    // Every field the form renders, as a browser submits them: the ones left alone arrive as ''.
    $this->post('/firefly/data', ['resource' => 'admin-record', 'op' => 'create', 'f' => [
        'email' => 'katherine@example.test', 'amount' => '7', 'active' => '', 'meta' => '', 'created_at' => '',
    ]])
        ->assertRedirect('/firefly/data?resource=admin-record&id=2')
        ->assertSessionHas('data-message', 'Created.');

    expect(DB::table('admin_records')->where(['id' => 2, 'email' => 'katherine@example.test', 'amount' => 7, 'active' => 1])->exists())->toBeTrue();
});

/**
 * The listing itself, rebuilt on the shared table system.
 *
 * WHAT THIS PINS IS THE LINKS, not the decoration. Every href on this page used to be four concatenated
 * strings — `$keepFilter`, `$keepSearch`, `$keepSize`, `$keepSort` — with a comment beside them asking the
 * next author not to forget one, and a sort link that dropped the filter widens the listing back to every
 * row: rows appear from nowhere and nothing fails. Now one ListingQuery carries the resource and the
 * filter, `sortLink()` is the only way a header href is written, and this is what says the carry survived.
 */
it('renders the listing through the shared table system, carrying the resource and the filter into every link', function () {
    /** @var DataBrowserTestCase $this */
    $this->exposeAdminRecords();
    DB::table('admin_records')->insert([
        ['id' => 2, 'email' => 'grace@example.test', 'amount' => 150, 'active' => 1, 'created_at' => null],
        ['id' => 3, 'email' => 'linus@example.test', 'amount' => 250, 'active' => 0, 'created_at' => null],
    ]);

    $html = (string) $this->get('/firefly/data?resource=admin-record&fk=active&fv=1')->assertStatus(200)->getContent();

    expect($html)
        // ONE table class, with the database's own type classes beside it rather than instead of it.
        ->toContain('<table class="ftable datatable">')
        ->toContain('<colgroup>')
        // The shared panel header, and the wording tests/Browser/AdminDataBrowserTest.php reads.
        ->toContain('2 total')
        // The shared pager's range readout.
        ->toContain('1–2 of 2')
        // A sort header, carrying the resource AND the filter it was clicked inside.
        ->toContain('resource=admin-record&amp;fk=active&amp;fv=1&amp;sort=amount')
        // The search form re-submits the filter, so searching inside it narrows rather than widens.
        ->toContain('<input type="hidden" name="fk" value="active">')
        ->toContain('<input type="hidden" name="fv" value="1">')
        // The one control that leaves the listing keeps carrying the resource and nothing else.
        ->toContain('New record');
});

/**
 * `?page=999` on a two-page listing is a hand-edited URL or a stale bookmark, not an error. The in-memory
 * listings clamp it because they know the total before they slice; SQL does not, so it comes back as an
 * empty slice with a real total — an empty table under a pager that says there are thirty rows. The last
 * page is fetched instead, which costs one extra query in a case no click can reach.
 */
it('renders the last page rather than an empty table when the page number is past the end', function () {
    /** @var DataBrowserTestCase $this */
    $this->exposeAdminRecords();

    $rows = [];
    foreach (range(2, 30) as $n) {
        $rows[] = ['id' => $n, 'email' => 'row-'.$n.'@example.test', 'amount' => $n, 'active' => 1, 'created_at' => null];
    }
    DB::table('admin_records')->insert($rows);

    $html = (string) $this->get('/firefly/data?resource=admin-record&size=25&page=999')->assertStatus(200)->getContent();

    expect($html)->toContain('30 total')
        ->toContain('26–30 of 30')
        ->toContain('row-30@example.test')
        ->not->toContain('No records yet');
});

/**
 * THE RIGID WIDTHS ARE NOT ONE NUMBER. Under `table-layout:fixed` a `<col>` is the whole story — a cell
 * cannot grow out of it, it can only clip — so a column sized for the wrong alphabet is a value the operator
 * never sees. `2026-01-01 10:00:00` is NINETEEN characters, which is what `TableColumn::stamp()` already
 * uses for the same string; an int, a float and a boolean need thirteen. Sized alike at thirteen, the stamp's
 * own span measures 143px of text inside a 98px box and the cell reads `2026-01-01 10…`.
 *
 * The count is the assertion because a `<col>` carries no class: this fixture has three columns that take
 * the narrow width (`id`, `amount`, `active`) and exactly one datetime (`created_at`).
 */
it('sizes a datetime column for the whole timestamp and the numeric ones for a figure', function () {
    /** @var DataBrowserTestCase $this */
    $this->exposeAdminRecords();

    $html = (string) $this->get('/firefly/data?resource=admin-record')->assertStatus(200)->getContent();

    expect(substr_count($html, 'calc(19ch + 2 * var(--row-x))'))->toBe(1)
        ->and(substr_count($html, 'calc(13ch + 2 * var(--row-x))'))->toBe(3)
        // And the value it has to hold really is the full instant, seconds included.
        ->and($html)->toContain('2026-01-01 10:00:00');
});

/**
 * ROWS PER PAGE HERE IS THE BROWSER'S OWN KEY, and it stopped being that the moment this page started
 * stating a size on every call: `DataBrowser::list()` was handed `$query->size` — the dashboard-wide
 * `firefly.admin.table.page-size`, 50 — so `DataBrowserSettings::clampPageSize()`'s "null means use the
 * configured default" branch became unreachable from the web UI and a browser documented at 25 rows silently
 * served 50. `TableSettings::boundedBy()` composes the browser's pair into the shared settings before the
 * request is parsed, which is what puts the key back in charge of the listing AND keeps the rows-per-page
 * control showing where it is.
 */
it('pages the listing at the data browser\'s own default rather than the shared table default', function () {
    /** @var DataBrowserTestCase $this */
    $this->exposeAdminRecords();

    $rows = [];
    foreach (range(2, 30) as $n) {
        $rows[] = ['id' => $n, 'email' => 'row-'.$n.'@example.test', 'amount' => $n, 'active' => 1, 'created_at' => null];
    }
    DB::table('admin_records')->insert($rows);

    $html = (string) $this->get('/firefly/data?resource=admin-record')->assertStatus(200)->getContent();

    expect($html)->toContain('30 total')
        ->toContain('1–25 of 30')
        ->toContain('page 1 of 2')
        // The control says which size it is at, and the shared set is still what it offers.
        ->toContain('<option value="25" selected>25</option>')
        ->toContain('<option value="200" >200</option>');
});

/**
 * A SEARCH BOX THAT CAN ONLY EVER ANSWER "NOTHING MATCHES" IS WORSE THAN NO SEARCH BOX.
 *
 * `DataSchema::searchable()` keeps only non-sensitive string columns, so a join table of integers — or one
 * whose only text column is masked — publishes none, and `DataQueryEngine::fetch()` short-circuits to
 * `[[], 0]` the moment that list is empty. Drawing the control anyway hands the operator a way to empty a
 * table that has rows, with `0 total` and "Nothing matches" and nothing on the page saying the resource has
 * no searchable column: it reads as data loss. The <header> this page moved off guarded the form with
 * exactly this condition, and every other fixture here has a string column, which is why the guard could go
 * missing with the suite still green.
 */
it('draws no search box on a resource with no searchable column, and still says how many rows there are', function () {
    /** @var DataBrowserTestCase $this */
    $this->exposeAdminLinks();

    $html = (string) $this->get('/firefly/data?resource=admin-link')->assertStatus(200)->getContent();

    expect($html)
        // The panel is the shared one and the grand total survives the missing form.
        ->toContain('<h2>Records</h2>')
        ->toContain('2 total')
        // No search control at all: not the input, not the submit, not the role that announces the form.
        ->not->toContain('role="search"')
        ->not->toContain('Search records…')
        ->not->toContain('type="search"')
        // And the rows really are there, which is what makes an empty answer a lie rather than a fact.
        ->toContain('1–2 of 2');
});

/**
 * The same page with a term already in the URL — a hand-edited link or a stale bookmark. The listing comes
 * back empty because the engine has no column to look in, and with no search form there is no "Clear"
 * inside it, so the empty state's own link is the only way back. It must therefore shed `q`.
 */
it('leaves a way back when a resource with nothing to search is asked for a term', function () {
    /** @var DataBrowserTestCase $this */
    $this->exposeAdminLinks();

    $html = (string) $this->get('/firefly/data?resource=admin-link&size=100&q=anything')->assertStatus(200)->getContent();

    // The anchor TEXT is in the assertion on purpose: the panel header's own "Clear" writes the same href,
    // and it is not drawn here — so matching the href alone would pass against a page that offers no way
    // out at all.
    expect($html)->toContain('Nothing matches')
        ->toContain('<a href="/firefly/data?resource=admin-link&amp;size=100">clear them all</a>')
        ->not->toContain('q=anything');
});

/**
 * CLEARING A CONDITION MUST NOT RESIZE THE TABLE UNDER THE READER. The three "clear" links leave the
 * filters behind, which is precisely why they cannot come from `$query->link()` — that re-emits the
 * carried filters — and building them from the bare resource URL instead dropped the page size with them:
 * set Rows to 100, apply a filter, clear it, and the listing snapped back to the configured 25. They are
 * built from `ListingQuery::own()` now, which is the listing's own position without the carry, minus
 * `page`, because widening a result set invalidates the offset into it.
 */
it('keeps the chosen page size and ordering on the links that clear a filter', function () {
    /** @var DataBrowserTestCase $this */
    $this->exposeAdminRecords();
    DB::table('admin_records')->insert([
        ['id' => 2, 'email' => 'grace@example.test', 'amount' => 150, 'active' => 1, 'created_at' => null],
        ['id' => 3, 'email' => 'linus@example.test', 'amount' => 250, 'active' => 0, 'created_at' => null],
    ]);

    $html = (string) $this->get('/firefly/data?resource=admin-record&size=100&sort=amount&dir=desc&q=example&fk=active&fv=1')
        ->assertStatus(200)->getContent();

    $clear = '/firefly/data?resource=admin-record&amp;q=example&amp;sort=amount&amp;dir=desc&amp;size=100';

    expect($html)
        // The banner above the table and the filter bar's own button, both still at 100 rows.
        ->toContain('<a href="'.$clear.'">clear</a>')
        ->toContain('<a class="act" href="'.$clear.'">Clear</a>')
        // Neither one carries the condition it exists to remove.
        ->and(substr_count($html, $clear.'&amp;fk='))->toBe(0);

    // And the size really is the one that was asked for, not the browser's default.
    expect($html)->toContain('<option value="100" selected>100</option>');
});
