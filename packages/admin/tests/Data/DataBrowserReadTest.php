<?php

declare(strict_types=1);

use Firefly\Actuator\Introspection\SensitiveValueMasker;
use Firefly\Admin\Data\DataFilter;
use Firefly\Admin\Data\DataQueryEngine;
use Firefly\Admin\Tests\Data\Support\DataBrowserTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(DataBrowserTestCase::class);

it('pages a PagingAndSortingRepository through findPaged and reports the grand total', function () {
    /** @var DataBrowserTestCase $this */
    $this->seedRecords();

    $listing = $this->browser()->list('admin-record', page: 2, perPage: 2);

    expect($listing->failed())->toBeFalse()
        ->and($listing->total)->toBe(5)
        ->and($listing->page)->toBe(2)
        ->and($listing->perPage)->toBe(2)
        ->and($listing->totalPages())->toBe(3)
        ->and($listing->hasNext())->toBeTrue()
        ->and($listing->hasPrevious())->toBeTrue()
        ->and(array_column($listing->rows, 'id'))->toBe([3, 4]);
});

it('orders by the identifier when no sort is asked for, so pages cannot overlap', function () {
    /** @var DataBrowserTestCase $this */
    $this->seedRecords();

    $listing = $this->browser()->list('admin-record');

    expect($listing->sort)->toBe('id')
        ->and($listing->direction)->toBe('asc')
        ->and(array_column($listing->rows, 'id'))->toBe([1, 2, 3, 4, 5]);
});

it('honours a sort on a known column and drops one the schema does not know', function () {
    /** @var DataBrowserTestCase $this */
    $this->seedRecords();

    $descending = $this->browser()->list('admin-record', sort: 'amount', direction: 'desc');
    expect($descending->sort)->toBe('amount')
        ->and(array_column($descending->rows, 'amount'))->toBe([450, 350, 250, 150, 50]);

    // A crafted column name is not quoted, escaped or passed through — it simply fails the membership test
    // and the listing falls back to the identifier.
    $crafted = $this->browser()->list('admin-record', sort: 'amount) ; drop table admin_records; --');
    expect($crafted->failed())->toBeFalse()
        ->and($crafted->sort)->toBe('id')
        ->and(Schema::hasTable('admin_records'))->toBeTrue();
});

it('masks a secret column in a listing but leaves a null one null', function () {
    /** @var DataBrowserTestCase $this */
    $this->seedRecords();

    $rows = $this->browser()->list('admin-record')->rows;

    expect($rows[0]['api_token'])->toBe(SensitiveValueMasker::MASK)
        ->and($rows[0]['recovery_phrase'])->toBe(SensitiveValueMasker::MASK)
        // Row 2 has no token; masking a null would claim a secret exists where none does.
        ->and($rows[1]['api_token'])->toBeNull()
        ->and($rows[0]['email'])->toBe('ada@example.test');

    expect(json_encode($rows))->not->toContain('sk_live_ada_secret');
});

it('searches through the repository with a BOUND term, not an interpolated one', function () {
    /** @var DataBrowserTestCase $this */
    $this->seedRecords();

    // An apostrophe is the classic break-out character. It finds its row because it was bound.
    $found = $this->browser()->list('admin-record', search: "o'brien");
    expect($found->failed())->toBeFalse()
        ->and($found->total)->toBe(1)
        ->and($found->rows[0]['email'])->toBe("o'brien@example.test");

    // And an outright injection attempt is just a string that matches nothing.
    $attack = $this->browser()->list('admin-record', search: "' OR 1=1; DROP TABLE admin_records; --");
    expect($attack->failed())->toBeFalse()
        ->and($attack->total)->toBe(0)
        ->and($attack->rows)->toBe([])
        ->and(Schema::hasTable('admin_records'))->toBeTrue()
        ->and(DB::table('admin_records')->count())->toBe(5);
});

it('pages a searched listing in SQL and counts only the matches', function () {
    /** @var DataBrowserTestCase $this */
    $this->seedRecords();

    $listing = $this->browser()->list('admin-record', page: 1, perPage: 2, search: 'example.test');

    expect($listing->total)->toBe(5)
        ->and($listing->rows)->toHaveCount(2)
        ->and($listing->search)->toBe('example.test');
});

it('never searches a masked column, so the search box is not an oracle', function () {
    /** @var DataBrowserTestCase $this */
    $this->seedRecords();

    expect($this->browser()->list('admin-record', search: 'sk_live')->total)->toBe(0);
});

it('falls back to findAll with an in-PHP slice for a plain CrudRepository', function () {
    /** @var DataBrowserTestCase $this */
    $this->seedNotes();

    $listing = $this->browser()->list('plain-note', page: 2, perPage: 2);

    expect($listing->failed())->toBeFalse()
        ->and($listing->total)->toBe(3)
        ->and($listing->rows)->toHaveCount(1)
        ->and($listing->rows[0]['title'])->toBe('Gamma')
        ->and($listing->rows[0]['id'])->toBe(3)
        // Read off a promoted PROTECTED property and a public one alike.
        ->and($listing->rows[0]['pinned'])->toBeFalse();
});

it('sorts and filters the in-PHP fallback without touching SQL', function () {
    /** @var DataBrowserTestCase $this */
    $this->seedNotes();

    $sorted = $this->browser()->list('plain-note', sort: 'title', direction: 'desc');
    expect(array_column($sorted->rows, 'title'))->toBe(['Gamma', 'Beta', 'Alpha']);

    $searched = $this->browser()->list('plain-note', search: 'note');
    expect($searched->total)->toBe(2)
        ->and(array_column($searched->rows, 'title'))->toBe(['Alpha', 'Beta']);

    $injected = $this->browser()->list('plain-note', search: "'; DROP TABLE admin_notes; --");
    expect($injected->total)->toBe(0)
        ->and(Schema::hasTable('admin_notes'))->toBeTrue();
});

/**
 * The em-dash rule, on the OTHER engine that orders rows in PHP. `null` and `''` are the absence of an answer
 * rather than an answer that sorts low, so they sit at the end of the listing in BOTH directions instead of
 * filling the first page of a descending sort. Both paths compare through `Firefly\Admin\RowComparator`,
 * which is what stops one column header from meaning two different orders on two pages of one dashboard.
 */
it('keeps null and empty values last in both directions on the in-PHP fallback', function () {
    /** @var DataBrowserTestCase $this */
    $this->seedNotes();
    DB::table('admin_notes')->insert(['id' => 4, 'title' => 'Delta', 'body' => '', 'pinned' => 0]);

    $ascending = $this->browser()->list('plain-note', sort: 'body', direction: 'asc');
    $descending = $this->browser()->list('plain-note', sort: 'body', direction: 'desc');

    // Gamma has a null body and Delta an empty one; both stay behind the rows that have something to show.
    expect(array_column($ascending->rows, 'title'))->toBe(['Alpha', 'Beta', 'Gamma', 'Delta'])
        ->and(array_column($descending->rows, 'title'))->toBe(['Beta', 'Alpha', 'Gamma', 'Delta']);
});

/**
 * The OTHER half of the shared comparator, on the engine that sorts a repository which cannot page: the
 * answer must not depend on the order `findAll()` handed the rows over in.
 *
 * A column that mixes numbers with anything else — `1.10`, `1.9`, `1.9-beta`, which no schema forbids —
 * has no consistent pairwise answer, and `usort()` over an inconsistent comparison returns whatever the
 * arrival order suggested. Here the arrival order is the repository's `orderBy('id')`, so the same three
 * bodies are re-inserted under three different id orders; a per-pair choice between arithmetic and natural
 * comparison gives three different listings, which is a row moving across a page boundary between two
 * requests for no reason the reader can see. The column is judged once, so all three agree.
 */
it('orders the in-PHP fallback identically whatever order the repository returned the rows in', function () {
    /** @var DataBrowserTestCase $this */
    $bodies = ['1.10', '1.9', '1.9-beta'];

    $listings = [];
    foreach ([[0, 1, 2], [2, 1, 0], [1, 2, 0]] as $arrival) {
        DB::table('admin_notes')->delete();
        foreach ($arrival as $position => $index) {
            DB::table('admin_notes')->insert([
                'id' => $position + 1,
                'title' => 'Note '.$index,
                'body' => $bodies[$index],
                'pinned' => 0,
            ]);
        }

        $listings[] = array_column($this->browser()->list('plain-note', sort: 'body')->rows, 'body');
    }

    expect($listings)->each->toBe(['1.9', '1.9-beta', '1.10']);
});

/**
 * `greater than` and `less than` over a BOOLEAN column, on BOTH engines, because a bool is exactly where the
 * two can disagree without anything failing.
 *
 * The unpaged path reads `pinned` off a promoted property and holds a real `bool`; its paged sibling reads a
 * tinyint out of the driver and asks SQL `where(pinned, '>', ?)` with `0` bound. What keeps those two
 * answers the same is that the in-PHP comparison is made on the SCALAR STRING of the cell — `'1'` and `''`,
 * the same shapes the driver binds — and not on the value as the listing would RENDER it. A bool rendered as
 * the word `true` is not a number, so it would fall to the natural-text comparison, where `t` sorts after
 * every digit: `greater than 0` would then match every row on the repository that cannot page and the right
 * rows on the one that can, and one filter in one URL would mean opposite things on two resources.
 */
it('compares a boolean column the same way on the in-PHP and the SQL filter paths', function () {
    /** @var DataBrowserTestCase $this */
    $this->seedNotes();
    $this->seedRecords();

    $pinned = $this->browser()->list('plain-note', filters: [new DataFilter('pinned', DataFilter::GT, '0')]);
    $unpinned = $this->browser()->list('plain-note', filters: [new DataFilter('pinned', DataFilter::LT, '1')]);

    // Alpha is the only pinned note, and `less than 1` is its complement — not every row, and not none.
    expect(array_column($pinned->rows, 'title'))->toBe(['Alpha'])
        ->and(array_column($unpinned->rows, 'title'))->toBe(['Beta', 'Gamma']);

    // The same two comparisons over the Eloquent resource, where a driver answers them.
    $active = $this->browser()->list('admin-record', filters: [new DataFilter('active', DataFilter::GT, '0')]);
    $inactive = $this->browser()->list('admin-record', filters: [new DataFilter('active', DataFilter::LT, '1')]);

    expect(array_column($active->rows, 'id'))->toBe([1, 2, 4])
        ->and(array_column($inactive->rows, 'id'))->toBe([3, 5]);
});

it('clamps the page size to the configured ceiling', function () {
    /** @var DataBrowserTestCase $this */
    $this->seedRecords();

    expect($this->browser()->list('admin-record', perPage: 10_000)->perPage)->toBe(200)
        ->and($this->browser(['enabled' => true, 'max-page-size' => 2])->list('admin-record', perPage: 500)->perPage)->toBe(2)
        ->and($this->browser()->list('admin-record', perPage: 0)->perPage)->toBe(1)
        ->and($this->browser()->list('admin-record', page: -5)->page)->toBe(1);
});

it('truncates a long value in a listing and shows it whole in the record', function () {
    /** @var DataBrowserTestCase $this */
    $this->seedRecords();
    $long = '{"note":"'.str_repeat('x', 400).'"}';
    DB::table('admin_records')->where('id', 1)->update(['meta' => $long]);

    $listed = $this->stringCell($this->browser()->list('admin-record')->rows[0], 'meta');
    expect(mb_strlen($listed))->toBe(DataQueryEngine::LIST_VALUE_LIMIT + 1)
        ->and($listed)->toEndWith("\u{2026}")
        ->and($this->recordOf($this->browser(), 'admin-record', 1)->fields['meta'])->toBe($long);
});

it('returns one record as an ordered field map', function () {
    /** @var DataBrowserTestCase $this */
    $this->seedRecords();

    $record = $this->recordOf($this->browser(), 'admin-record', 3);

    expect($record->id)->toBe(3)
        // Schema order, not driver order — a detail page whose fields move between rows is unreadable.
        ->and(array_keys($record->fields))->toBe(['id', 'email', 'api_token', 'recovery_phrase', 'amount', 'active', 'meta', 'created_at'])
        ->and($record->fields['email'])->toBe('grace@example.test')
        ->and($record->fields['api_token'])->toBe(SensitiveValueMasker::MASK)
        ->and($record->fields['created_at'])->toBe('2026-01-03 10:00:00');

    $rows = $record->rows();
    expect($rows[0]['name'])->toBe('id')
        ->and($rows[0]['identifier'])->toBeTrue()
        ->and($rows[0]['editable'])->toBeFalse()
        ->and($rows[1]['label'])->toBe('Email')
        ->and($rows[1]['editable'])->toBeTrue()
        ->and($rows[2]['sensitive'])->toBeTrue();
});

it('returns null for a record that is not there', function () {
    /** @var DataBrowserTestCase $this */
    $this->seedRecords();
    $this->seedNotes();

    expect($this->browser()->find('admin-record', 999))->toBeNull()
        ->and($this->browser()->find('nope', 1))->toBeNull()
        ->and($this->recordOf($this->browser(), 'plain-note', 2)->fields['title'])->toBe('Beta');
});

it('reports a query failure without leaking the SQL or the bindings', function () {
    /** @var DataBrowserTestCase $this */
    $this->seedRecords();
    $browser = $this->browser();
    Schema::drop('admin_records');

    $listing = $browser->list('admin-record', search: 'ada@example.test');

    expect($listing->failed())->toBeTrue()
        ->and($listing->rows)->toBe([])
        ->and($listing->total)->toBe(0)
        ->and($listing->error)->toContain('The listing query failed')
        // The repository translates the driver's QueryException (firefly/data's PersistenceExceptionTranslator),
        // so the class the reason names is the kernel's; the raw one is on `previous`, never on the page.
        ->and($listing->error)->toContain('BadSqlGrammarException')
        ->and($listing->error)->not->toContain('QueryException')
        // The exception message would have carried `select * from "admin_records" ...` and the bound term.
        ->and(strtolower((string) $listing->error))->not->toContain('select')
        ->and($listing->error)->not->toContain('ada@example.test')
        // A detail read of a broken resource is a 404, never a stack trace.
        ->and($browser->find('admin-record', 1))->toBeNull();
});

it('shows nothing at all while the browser is switched off', function () {
    /** @var DataBrowserTestCase $this */
    $this->seedRecords();
    $browser = $this->browser([]);

    expect($browser->isEnabled())->toBeFalse()
        ->and($browser->isWritable())->toBeFalse()
        ->and($browser->resources())->toBe([])
        ->and($browser->resource('admin-record'))->toBeNull()
        ->and($browser->schema('admin-record'))->toBeNull()
        ->and($browser->find('admin-record', 1))->toBeNull()
        ->and($browser->list('admin-record')->failed())->toBeTrue()
        ->and($browser->list('admin-record')->error)->toContain('firefly.admin.data.enabled');
});
