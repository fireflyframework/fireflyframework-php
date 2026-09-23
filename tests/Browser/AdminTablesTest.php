<?php

declare(strict_types=1);

use Firefly\Tests\Browser\Support\AdminDashboardBrowserTestCase;

pest()->extend(AdminDashboardBrowserTestCase::class);

/**
 * THE COMPLAINT, MEASURED. With seven routes on the page, `/greetings/{name}` rendered as six stacked lines
 * of three or four characters beside 1100px of empty column, because `overflow-wrap:anywhere` made the
 * path's min-content width one glyph under auto layout. A path cell is now one line high.
 */
it('draws a route path on one line instead of six', function (): void {
    /** @var AdminDashboardBrowserTestCase $this */
    visit('/firefly/mappings')
        ->assertScript(<<<'JS'
            (() => {
                const cell = [...document.querySelectorAll('td.t-path')].find(c => c.textContent.includes('/greetings/'));
                if (cell === undefined) { return 'no /greetings/ row'; }
                // LINE BOXES, NOT THE CELL'S HEIGHT. Every cell in a row is as tall as the row, and the
                // row is two lines tall because the Handler cell draws the leaf over its stem — so the
                // path cell's own box says nothing about how the path was laid out. A Range over the
                // cell's contents reports one rectangle per line box, which is the thing being counted:
                // it was six, and it is one.
                const range = document.createRange();
                range.selectNodeContents(cell);
                return range.getClientRects().length === 1;
            })()
            JS, true)
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'admin-mappings');
});

/**
 * THE PADDING TRAP, MEASURED. `box-sizing:border-box` is global on this page, so a 7.5ch pill column is
 * 7.5 characters MINUS 2×14px — about 34px of content, in which DELETE clips. The width is emitted as
 * `calc(7.5ch + 2 * var(--row-x))`, and this is the assertion that says so in pixels.
 */
it('does not clip the verb DELETE in the method column', function (): void {
    /** @var AdminDashboardBrowserTestCase $this */
    visit('/firefly/mappings')
        ->assertScript(<<<'JS'
            (() => {
                const pill = [...document.querySelectorAll('td.t-pill .verb')].find(v => v.textContent.trim() === 'DELETE');
                if (pill === undefined) { return 'no DELETE row'; }
                const cell = pill.closest('td');
                return pill.scrollWidth <= pill.clientWidth && cell.scrollWidth <= cell.clientWidth;
            })()
            JS, true)
        ->assertNoJavaScriptErrors();
});

// The handler column used to absorb every spare pixel through `.cls{max-width:0;width:100%}` and fling the
// Name column to the far edge. Every column now has a declared width, and none of them is the whole table.
it('gives every column a declared width and none of them the whole table', function (): void {
    /** @var AdminDashboardBrowserTestCase $this */
    visit('/firefly/mappings')
        ->assertScript(<<<'JS'
            (() => {
                const table = document.querySelector('table.ftable');
                const cols = [...table.querySelectorAll('col')];
                if (cols.length !== 4) { return 'expected four cols, got ' + cols.length; }
                const widths = [...table.querySelectorAll('thead th')].map(th => th.getBoundingClientRect().width);
                return getComputedStyle(table).tableLayout === 'fixed'
                    && widths.every(w => w > 40)
                    && Math.max(...widths) < table.getBoundingClientRect().width * 0.6;
            })()
            JS, true)
        ->assertNoJavaScriptErrors();
});

// A namespace is located by its TAIL, so the overflow is anchored at the start of the stem.
it('elides a handler namespace from the left, and its short name from the right', function (): void {
    /** @var AdminDashboardBrowserTestCase $this */
    visit('/firefly/mappings')
        ->assertScript(<<<'JS'
            (() => {
                const stem = document.querySelector('td.t-qual .ns.stem');
                const name = document.querySelector('td.t-qual .nm');
                return getComputedStyle(stem).direction === 'rtl'
                    && getComputedStyle(name).direction === 'ltr'
                    && getComputedStyle(stem).textOverflow === 'ellipsis';
            })()
            JS, true)
        ->assertNoJavaScriptErrors();
});

it('searches and sorts on the server, from links that keep the rest of the state', function (): void {
    /** @var AdminDashboardBrowserTestCase $this */
    visit('/firefly/mappings')
        ->fill('q', 'orders')
        ->press('Search')
        ->assertQueryStringHas('q', 'orders')
        ->assertSee('OrderController')
        ->assertDontSee('GreetingController')
        ->click('Path')
        ->assertQueryStringHas('sort', 'path')
        ->assertQueryStringHas('q', 'orders')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'admin-mappings-searched');
});
