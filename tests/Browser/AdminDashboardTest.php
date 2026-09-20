<?php

declare(strict_types=1);

use Firefly\Admin\Web\AdminPage;
use Firefly\Tests\Browser\Support\BrowserTestCase;

pest()->extend(BrowserTestCase::class);

/**
 * Every dashboard page, by slug → the <h1> it renders. Pinned by hand so a page that silently rendered
 * the "unavailable" or "no such page" view would fail: both of those show the nav label as their heading,
 * which is why the two sentences below are asserted absent on every page.
 */
$pages = [
    '' => 'Overview',
    'health' => 'Health',
    'metrics' => 'Metrics',
    'http' => 'HTTP traffic',
    'beans' => 'Beans',
    'graph' => 'Bean graph',
    'conditions' => 'Conditions',
    'mappings' => 'Routes',
    'scheduled' => 'Scheduled tasks',
    'env' => 'Environment',
    'configprops' => 'Config properties',
    'caches' => 'Caches',
    'loggers' => 'Loggers',
    'settings' => 'Feature switches',
    'datasource' => 'Datasource',
    'data' => 'Browse data',
    'data-map' => 'Entity map',
];

it('covers every page the dashboard declares', function () use ($pages): void {
    /** @var BrowserTestCase $this */
    $declared = array_map(static fn (AdminPage $page): string => $page->slug, AdminPage::all());

    expect(array_keys($pages))->toEqualCanonicalizing($declared);
});

it('renders the dashboard page', function (string $slug, string $heading): void {
    /** @var BrowserTestCase $this */
    visit('/firefly'.($slug === '' ? '' : '/'.$slug))
        // The navigation's own HTTP status: the admin answers its unavailable / no-such-page / data-disabled
        // views with a 404 and the framework's error page prints the request path, so text alone cannot
        // tell a rendered page from a well-worded refusal. Chromium exposes the status on the navigation
        // timing entry.
        ->assertScript("performance.getEntriesByType('navigation')[0].responseStatus", 200)
        ->assertSee($heading)
        ->assertDontSee('This page has no endpoint to read')
        ->assertDontSee('No such page')
        ->assertDontSee('The data browser is off')
        ->assertDontSee('RESOURCE_NOT_FOUND')
        ->assertDontSee('INTERNAL_ERROR')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'admin-'.($slug === '' ? 'overview' : $slug));
})->with(array_map(static fn (string $slug, string $heading): array => [$slug, $heading], array_keys($pages), $pages));

it('switches the theme with the toolbar button and remembers it across a reload', function (): void {
    /** @var BrowserTestCase $this */
    $page = visit('/firefly');

    $page->assertAttribute('html[data-theme]', 'data-theme', 'auto')
        ->click('#theme')
        ->assertAttribute('html[data-theme]', 'data-theme', 'dark')
        ->screenshot(filename: 'admin-theme-dark')
        ->refresh()
        ->assertAttribute('html[data-theme]', 'data-theme', 'dark')
        ->click('#theme')
        ->assertAttribute('html[data-theme]', 'data-theme', 'light')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'admin-theme-light');
});

it('renders the overview in dark mode', function (): void {
    /** @var BrowserTestCase $this */
    visit('/firefly')
        ->inDarkMode()
        ->assertSee('Overview')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'admin-overview-dark');
});

it('renders the overview at phone width, with the menu still reachable', function (): void {
    /** @var BrowserTestCase $this */
    visit('/firefly')
        ->on()->mobile()
        ->assertSee('Overview')
        ->assertSeeLink('Feature switches')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'admin-overview-mobile');
});
