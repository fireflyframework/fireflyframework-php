<?php

declare(strict_types=1);

use Firefly\Admin\Tests\Data\Support\DataBrowserTestCase;
use Illuminate\Support\Facades\DB;

uses(DataBrowserTestCase::class);

/**
 * WITHOUT A TIEBREAK, PAGING A NON-UNIQUE SORT IS A LOTTERY. `ORDER BY active` over forty rows that all
 * share an `active` leaves the engine free to return them in a different order for the query that builds
 * page 1 and the query that builds page 2 — so a row appears on both and another on neither, and the
 * operator reads a table that is missing records which are really there. A second, always-ascending key
 * over the identifier removes the freedom.
 *
 * THE ORDER BY ITSELF IS ASSERTED, not only the rows it produced, and that is deliberate. SQLite's sorter
 * happens to return insertion order for a tied key on a table this small, which HIDES the defect behind a
 * green behavioural test while the same ORDER BY on MySQL or Postgres re-orders freely between two
 * requests. What the fix actually changes is the statement the engine hands the driver, so that is what is
 * pinned; the walk over every page beneath it is the behavioural regression that would catch a driver
 * which does re-order.
 *
 * @param  Closure(): mixed  $listing
 * @return list<string> every ORDER BY clause the browser sent to `$table` while the callback ran
 */
function orderClausesFor(string $table, Closure $listing): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    $listing();

    $clauses = [];
    foreach (DB::getQueryLog() as $entry) {
        $sql = $entry['query'];

        // `from "<table>"` rather than the bare name: sqlite's own column introspection mentions the
        // table inside `pragma_table_info('<table>')` and carries an `order by cid asc` of its own.
        if (! str_contains($sql, 'from "'.$table.'"')) {
            continue;
        }

        if (preg_match('/ order by (.+?)(?: limit | offset |$)/i', $sql, $matches) === 1) {
            $clauses[] = $matches[1];
        }
    }

    DB::disableQueryLog();

    return $clauses;
}

it('breaks a tied sort on the identifier, on the SQL path', function () {
    /** @var DataBrowserTestCase $this */
    $this->seedRecords();
    $browser = $this->browser();

    $clauses = orderClausesFor('admin_records', static fn (): mixed => $browser->list('admin-record', 1, 2, 'active', 'desc'));

    expect($clauses)->not->toBeEmpty()
        ->and($clauses[0])->toContain('"active" desc')
        // ASCENDING, under a descending primary. It is an identity, not a second ordering: mirroring it
        // would make the order WITHIN a tie depend on the direction it is breaking the tie inside.
        ->and($clauses[0])->toContain('"id" asc');
});

it('does not repeat the identifier when it is already the sort column', function () {
    /** @var DataBrowserTestCase $this */
    $this->seedRecords();
    $browser = $this->browser();

    $clauses = orderClausesFor('admin_records', static fn (): mixed => $browser->list('admin-record', 1, 2, 'id', 'desc'));

    // `ORDER BY id DESC, id ASC` is at best noise and at worst an index the planner declines to use.
    expect($clauses)->not->toBeEmpty()->and($clauses[0])->toBe('"id" desc');
});

it('sees every row exactly once when paging a sort whose values all tie', function () {
    /** @var DataBrowserTestCase $this */
    $rows = [];
    foreach (range(1, 40) as $n) {
        $rows[] = [
            'id' => $n,
            'email' => 'row-'.str_pad((string) $n, 2, '0', STR_PAD_LEFT).'@example.test',
            'api_token' => null,
            'recovery_phrase' => null,
            'amount' => 10,
            'active' => 1,
            'meta' => null,
            'created_at' => null,
        ];
    }
    DB::table('admin_records')->insert($rows);

    $browser = $this->browser();

    $seen = [];
    foreach (range(1, 4) as $page) {
        foreach ($browser->list('admin-record', $page, 10, 'active', 'desc')->rows as $row) {
            $seen[] = $this->stringCell($row, 'email');
        }
    }

    expect($seen)->toHaveCount(40)->and(array_unique($seen))->toHaveCount(40);
});

it('keeps the tiebreak ascending under a descending primary sort, so the tie order never mirrors', function () {
    /** @var DataBrowserTestCase $this */
    $this->seedRecords();
    $browser = $this->browser();

    // Every seeded row shares nothing but `amount`'s type, so the tie is made here: one value in `amount`
    // across all five, leaving the identifier as the only thing that can decide the order.
    DB::table('admin_records')->update(['amount' => 7]);

    $descending = $browser->list('admin-record', 1, 10, 'amount', 'desc');
    $ascending = $browser->list('admin-record', 1, 10, 'amount', 'asc');

    expect(array_column($descending->rows, 'id'))->toBe([1, 2, 3, 4, 5])
        ->and(array_column($ascending->rows, 'id'))->toBe(array_column($descending->rows, 'id'));
});

/**
 * The same tiebreak on the OTHER engine — the `findAll()`-then-slice-in-PHP fallback a repository that
 * cannot page takes.
 *
 * `usort` is stable in PHP 8, and this fixture's `findAll()` happens to return its rows in identifier
 * order, so the array being stabilised already carries the answer the tiebreak gives: this passes before
 * the fix as well as after it. It is kept because stability of the SORT is not stability of the PAGE
 * BOUNDARY — the array is rebuilt from the repository on every request, and a repository whose `findAll()`
 * returns rows in whatever order its storage found convenient makes the tied order move between the
 * request that builds page 1 and the request that builds page 2. This is what says so.
 */
it('breaks a tied sort on the identifier on the in-PHP fallback too', function () {
    /** @var DataBrowserTestCase $this */
    $this->seedNotes();
    DB::table('admin_notes')->insert([
        ['id' => 4, 'title' => 'Delta', 'body' => 'fourth note', 'pinned' => 0],
        ['id' => 5, 'title' => 'Epsilon', 'body' => 'fifth note', 'pinned' => 0],
        ['id' => 6, 'title' => 'Zeta', 'body' => 'sixth note', 'pinned' => 0],
    ]);
    DB::table('admin_notes')->update(['pinned' => 0]);

    $browser = $this->browser();

    $seen = [];
    foreach (range(1, 3) as $page) {
        $seen = [...$seen, ...array_column($browser->list('plain-note', $page, 2, 'pinned', 'desc')->rows, 'id')];
    }

    expect($seen)->toBe([1, 2, 3, 4, 5, 6]);
});
