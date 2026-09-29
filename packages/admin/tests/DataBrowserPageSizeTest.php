<?php

declare(strict_types=1);

use Firefly\Admin\Tests\Support\DataBrowserPageSizeTestCase;
use Illuminate\Support\Facades\DB;

uses(DataBrowserPageSizeTestCase::class);

/**
 * `firefly.admin.data.page-size`, from the deployment's point of view.
 *
 * A KEY THAT DECIDES NOTHING IS A KEY THAT LIES. This page used to state its size on every call to
 * `DataBrowser::list()` — the dashboard-wide `firefly.admin.table.page-size` — so the browser's own default
 * could never be reached and a deployment that had asked for ten rows was served fifty. Nothing failed,
 * because nothing pinned the number. `TableSettings::boundedBy()` composes the browser's pair into the
 * shared settings BEFORE the request is parsed, which is what puts the key back in charge of the listing.
 *
 * The rows-per-page control is the second half and it is not decoration: the configured default is forced
 * into the offered set, so the `<select>` can show the size the table is at. Without that it would show the
 * first option (25) while ten rows were on screen, and pressing Apply would resize the table the operator
 * was reading — the exact failure TableSettings' closed set exists to prevent.
 */
it('pages at the configured size and offers it beside the shared set', function () {
    /** @var DataBrowserPageSizeTestCase $this */
    $this->exposeAdminRecords();

    $rows = [];
    foreach (range(2, 30) as $n) {
        $rows[] = ['id' => $n, 'email' => 'row-'.$n.'@example.test', 'amount' => $n, 'active' => 1, 'created_at' => null];
    }
    DB::table('admin_records')->insert($rows);

    $html = (string) $this->get('/firefly/data?resource=admin-record')->assertStatus(200)->getContent();

    expect($html)->toContain('30 total')
        ->toContain('1–10 of 30')
        ->toContain('page 1 of 3')
        // The control says where it is, and still offers everything the dashboard-wide set does.
        ->toContain('<option value="10" selected>10</option>')
        ->toContain('<option value="25" >25</option>')
        ->toContain('<option value="200" >200</option>')
        // And the size is the DEFAULT now, so no link spells it out — a URL that pins a default is a
        // bookmark that keeps rendering ten rows after the deployment has changed its mind.
        ->toContain('href="/firefly/data?resource=admin-record&amp;page=2"')
        ->not->toContain('size=10');
});

/**
 * The other direction: a size the operator picks is carried, and it is carried because it is no longer the
 * default. The offered set is the shared one, so 25 is a size this listing will serve even though its own
 * default is ten — the browser's `max-page-size` is what decides whether a size is servable, not its
 * `page-size`.
 */
it('carries a chosen size that is not the configured default', function () {
    /** @var DataBrowserPageSizeTestCase $this */
    $this->exposeAdminRecords();

    $rows = [];
    foreach (range(2, 30) as $n) {
        $rows[] = ['id' => $n, 'email' => 'row-'.$n.'@example.test', 'amount' => $n, 'active' => 1, 'created_at' => null];
    }
    DB::table('admin_records')->insert($rows);

    $html = (string) $this->get('/firefly/data?resource=admin-record&size=25')->assertStatus(200)->getContent();

    expect($html)->toContain('1–25 of 30')
        ->toContain('<option value="25" selected>25</option>')
        ->toContain('href="/firefly/data?resource=admin-record&amp;size=25&amp;page=2"');
});
