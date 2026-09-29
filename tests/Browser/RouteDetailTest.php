<?php

declare(strict_types=1);

use Firefly\Tests\Browser\Support\AdminDashboardBrowserTestCase;

pest()->extend(AdminDashboardBrowserTestCase::class);

it('opens the route contract through a keyboard-accessible link and focuses its heading', function (): void {
    visit('/firefly/mappings?q=orders&sort=path')
        ->keys('a[data-route="POST /orders"]', 'Enter')
        ->assertSee('Request · what the caller sends')
        ->assertSee('Default success status')
        ->assertScript('document.activeElement.id', 'route-detail')
        ->assertScript("getComputedStyle(document.getElementById('route-detail')).outlineStyle", 'solid')
        ->assertScript("(async () => (await axe.run(document.querySelector('main'), {runOnly:['color-contrast']})).violations.map(v => v.id))()", [])
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'route-detail-light');
});

it('keeps the complete detail and native disclosure controls usable with JavaScript disabled', function (): void {
    $page = visit('/firefly/mappings', ['javaScriptEnabled' => false]);
    $page
        ->assertSee('Mappings');
    $page->keys('a[data-route="POST /orders"]', 'Enter');
    $page
        ->assertSee('Request body')
        ->keys('Dispatch order and inspection limits', 'Enter')
        ->assertSee('Security authorization is not inferred')
        ->keys('.route-back', 'Enter')
        ->assertSee('Mappings');
});

it('contains the wide binding table inside its scrollport on a phone', function (): void {
    $page = visit('/firefly/mappings?route=POST%20%2Forders')->on()->mobile();
    $page
        ->assertSee('Request body')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth', true)
        ->assertScript("document.querySelector('.route-bindings').closest('.tw').scrollWidth > document.querySelector('.route-bindings').closest('.tw').clientWidth", true)
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'route-detail-phone');
    $page->script("document.getElementById('route-request').scrollIntoView()");
    $page->screenshot(filename: 'route-detail-phone-contract');
});

it('renders the route contract and failure explanations in the dark theme', function (): void {
    visit('/firefly/mappings?route=POST%20%2Forders')->inDarkMode()
        ->assertSee('MALFORMED_BODY')
        ->assertScript("(async () => (await axe.run(document.querySelector('main'), {runOnly:['color-contrast']})).violations.map(v => v.id))()", [])
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'route-detail-dark');
});

it('keeps the lower contract panels readable and disclosures operable', function (): void {
    $page = visit('/firefly/mappings?route=POST%20%2Forders');
    $page->keys('Dispatch order and inspection limits', 'Enter')
        ->assertSee('Security authorization is not inferred');
    $page->script("document.querySelector('main').scrollTop = document.querySelector('main').scrollHeight");
    $page->screenshot(filename: 'route-detail-lower')->assertNoJavaScriptErrors();
});
