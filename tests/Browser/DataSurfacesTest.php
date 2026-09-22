<?php

declare(strict_types=1);

use Firefly\Tests\Browser\Support\DataSurfacesBrowserTestCase;
use Illuminate\Support\Facades\DB;

pest()->extend(DataSurfacesBrowserTestCase::class);

/**
 * The data wave as a browser sees it: the `db` health indicator that is now on by default, the datasource
 * page's data-layer panel (exception translation, the transaction timeouts), and — through the data browser —
 * a duplicate key refused with the sentence the translated DuplicateKeyException is worded by, not a 500.
 * A 422 from the skeleton's POST /orders is ValidationErrorsTest's subject and is not repeated here.
 */
it('lists the db indicator UP on the health page, with no key set for it — the data wave\'s default', function (): void {
    /** @var DataSurfacesBrowserTestCase $this */
    visit('/firefly/health')
        ->assertSee('Health')
        ->assertSeeIn('tr:has(td:text-is("db")) span.chip', 'UP')
        ->assertSeeIn('tr:has(td:text-is("db"))', 'sqlite')
        ->assertSeeIn('tr:has(td:text-is("ping")) span.chip', 'UP')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'data-health');
});

it('shows the data layer\'s settings on the datasource page', function (): void {
    /** @var DataSurfacesBrowserTestCase $this */
    visit('/firefly/datasource')
        ->assertSee('Datasource')
        ->assertSee('Data layer')
        ->assertSeeIn('.stat:has-text("Exception translation") .chip', 'on')
        ->assertSeeIn('.stat:has-text("Default transaction timeout") dd', 'none')
        ->assertSeeIn('.stat:has-text("Driver statement timeout") .chip', 'on')
        ->assertSee('firefly.data.exception-translation.enabled')
        ->assertSee('Connections')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'data-datasource');
});

it('refuses a duplicate unique value through the data browser with the translated sentence, not a 500', function (): void {
    /** @var DataSurfacesBrowserTestCase $this */
    visit('/firefly/data')
        ->assertSee('Browser Subscriber')
        ->assertSee('Order Entity')
        ->assertNoJavaScriptErrors();

    visit('/firefly/data?resource=browser-subscriber&new=1')
        ->assertSee('New browser subscriber')
        ->fill('[name="f[email]"]', DataSurfacesBrowserTestCase::EXISTING_EMAIL)
        ->fill('[name="f[name]"]', 'Ada, again')
        ->press('Create record')
        // Back on the create form, with the outcome flashed — the DuplicateKeyException's sentence, never the
        // driver's message with the statement and the bound values in it.
        ->assertQueryStringHas('new', '1')
        ->assertSee('The insert failed: a row with the same unique value already exists.')
        ->assertDontSee('500')
        ->assertDontSee('INTERNAL_ERROR')
        ->assertDontSee('insert into')
        ->assertDontSee('UNIQUE constraint failed')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'data-duplicate');

    expect(DB::table('browser_subscribers')->count())->toBe(1);
});
