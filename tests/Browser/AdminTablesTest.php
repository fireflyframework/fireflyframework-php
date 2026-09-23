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

/**
 * THE WEIGHTS, IN PIXELS, BECAUSE A BOUND PROVES NOTHING HERE. The handler column used to absorb every
 * spare pixel through `.cls{max-width:0;width:100%}` and fling the Name column to the far edge; the
 * colgroup declares 5/4/3 instead. This assertion is the declared proportion itself and not "no column is
 * wider than 60% of the table", because that bound holds perfectly on a broken page: the first colgroup
 * emitted a `calc()` mixing a percentage with a subtracted length, which is not resolvable under
 * `table-layout:fixed`, so Chromium sized all three columns `auto` and drew equal thirds — 453/454/452 on
 * a 1445px table, every one of them comfortably under 60%.
 */
it('splits the width the pill column leaves 5:4:3 between Path, Handler and Name', function (): void {
    /** @var AdminDashboardBrowserTestCase $this */
    visit('/firefly/mappings')
        ->assertScript(<<<'JS'
            (() => {
                const table = document.querySelector('table.ftable');
                const cols = [...table.querySelectorAll('col')];
                if (cols.length !== 4) { return 'expected four cols, got ' + cols.length; }
                const layout = getComputedStyle(table).tableLayout;
                if (layout !== 'fixed') { return 'table-layout is ' + layout; }

                const got = [...table.querySelectorAll('thead th')].map(th => th.getBoundingClientRect().width);

                // The pill column is 7.5 characters PLUS both cell paddings, and both halves are measured
                // rather than assumed: `ch` is the mono advance the sheet gives `table.ftable colgroup`,
                // and `--row-x` follows whatever density the deployment configured.
                const ruler = document.createElement('div');
                ruler.style.cssText = 'position:absolute;visibility:hidden;font:12.5px var(--mono);width:7.5ch';
                document.body.appendChild(ruler);
                const rowX = parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--row-x'));
                const pill = ruler.getBoundingClientRect().width + 2 * rowX;
                ruler.remove();

                // What the fixed-layout engine hands to the percentage columns is everything the declared
                // length left, and 5/4/3 of that is what the Routes view asked for.
                const leftover = table.getBoundingClientRect().width - got[0];
                const want = [pill, leftover * 5 / 12, leftover * 4 / 12, leftover * 3 / 12];

                return want.every((w, i) => Math.abs(w - got[i]) <= 2)
                    || 'got ' + got.map(w => w.toFixed(1)).join('/')
                       + ', wanted ' + want.map(w => w.toFixed(1)).join('/');
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

/**
 * THE AUTO-LAYOUT PAGES STILL DEPEND ON `overflow-wrap:anywhere`, AND NOTHING USED TO SAY SO. Seven
 * listings that this wave has not rebuilt yet — env, configprops, beans, health, http, metrics, the
 * overview — still draw their cells as `td.mono.wrap` under `table-layout:auto`, where the column widths
 * come from the CONTENT. Per CSS Text 3 only `anywhere` contributes its break opportunities to min-content
 * sizing, so it is the one value that lets a token with no break of its own wrap INSIDE its column instead
 * of widening the table past the panel; `break-word` looks equivalent, breaks the same token at render
 * time, and sizes the column to the whole token.
 *
 * The scenario seeds its own worst case rather than hoping the process holds a long value: what is under
 * test is min-content sizing, so the input has to be a run with no break opportunity in it, and the
 * resolved `firefly.*` configuration this page happens to render is not reliably one.
 */
it('keeps a long unbreakable value inside the Environment panel', function (): void {
    /** @var AdminDashboardBrowserTestCase $this */
    visit('/firefly/env')
        ->assertScript(<<<'JS'
            (() => {
                const body = document.querySelector('#env-body');
                if (body === null) { return 'no env table on the page'; }
                const wrapper = body.closest('.tw');
                const overflow = () => wrapper.scrollWidth - wrapper.clientWidth;

                const before = overflow();
                const row = document.createElement('tr');
                row.innerHTML = '<td class="mono wrap"></td><td class="mono dim wrap"></td>';
                row.children[0].textContent = 'firefly.browser-fixture.long';
                row.children[1].textContent = 'QUJDREVGR0hJSktMTU5PUFFSU1RVVldYWVowMTIzNDU2Nzg5'.repeat(3);
                body.appendChild(row);
                const after = overflow();
                row.remove();

                return (before <= 1 && after <= 1)
                    || 'the env table overflows its panel: ' + before + 'px before the long value, ' + after + 'px after';
            })()
            JS, true)
        ->assertNoJavaScriptErrors();
});

/**
 * THE ONLY ASSERTION THAT WILL KEEP THIS FIXED. The header has always CARRIED `position:sticky`, so a
 * markup assertion proves nothing — it proved nothing for as long as the defect existed. This scrolls the
 * wrapper and measures the <th> against it, and it asserts the wrapper actually moved: before the fix
 * `scrollTop` stays 0 because the box has no height to overflow, and a test that only compared the two
 * rectangles would have passed on the broken sheet.
 */
it('pins the column headers to the top of the table while its rows scroll', function (): void {
    /** @var AdminDashboardBrowserTestCase $this */
    visit('/firefly/beans?size=200')
        ->assertScript(<<<'JS'
            (() => {
                const wrapper = document.querySelector('.tw');
                const header = document.querySelector('thead th');
                wrapper.scrollTop = 600;
                if (wrapper.scrollTop < 100) { return 'the wrapper did not scroll: ' + wrapper.scrollTop; }
                const offset = header.getBoundingClientRect().top - wrapper.getBoundingClientRect().top;
                return Math.abs(offset) < 2 ? true : 'header drifted to ' + offset;
            })()
            JS, true)
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'admin-beans-sticky');
});

// Under border-collapse the sticky cell's border belongs to the table's border grid and stays behind with
// the rows, so the header scrolls out from under its own underline. An inset shadow travels with the cell.
it('keeps the rule under the pinned header', function (): void {
    /** @var AdminDashboardBrowserTestCase $this */
    visit('/firefly/beans')
        ->assertScript("getComputedStyle(document.querySelector('thead th')).boxShadow.includes('inset')", true)
        ->assertNoJavaScriptErrors();
});

/**
 * THE OPT-OUT, MEASURED ON A REAL ELEMENT. `.tw.free` spent its first commit selecting nothing: no view in
 * the package put `free` on a wrapper, and the test beside it looked for the rule's TEXT in the stylesheet,
 * which a rule that can never match still satisfies. This reads the computed `max-height` off the elements
 * themselves — `none` on the overview's headerless panels, a resolved length on a listing — so the day
 * somebody drops the class from a view, or the specificity of the two rules flips, the assertion fails.
 */
it('frees the overview panels from the scrollport and leaves the listings inside it', function (): void {
    /** @var AdminDashboardBrowserTestCase $this */
    visit('/firefly')
        ->assertScript(<<<'JS'
            (() => {
                const free = [...document.querySelectorAll('.tw')];
                if (free.length === 0) { return 'the overview drew no table wrappers'; }
                for (const wrapper of free) {
                    if (!wrapper.classList.contains('free')) { return 'a headerless overview panel kept the scrollport'; }
                    const height = getComputedStyle(wrapper).maxHeight;
                    if (height !== 'none') { return 'the opt-out did not apply: max-height ' + height; }
                    // And it is not a scroller of its own, which is the point of declining the height.
                    if (wrapper.scrollHeight - wrapper.clientHeight > 1) { return 'a freed panel still scrolls'; }
                }
                return true;
            })()
            JS, true)
        ->assertNoJavaScriptErrors();

    visit('/firefly/beans?size=200')
        ->assertScript(<<<'JS'
            (() => {
                const wrapper = document.querySelector('.tw');
                if (wrapper.classList.contains('free')) { return 'a listing took the opt-out and lost its sticky header'; }
                const height = getComputedStyle(wrapper).maxHeight;
                return /^\d+(\.\d+)?px$/.test(height) ? true : 'the listing has no scrollport: max-height ' + height;
            })()
            JS, true)
        ->assertNoJavaScriptErrors();
});

/**
 * THE SCROLL RESTORE, BOTH HALVES. The whole reason it exists is that giving `.tw` a height took the reading
 * position away from the browser: `main` used to be the scrollport and a reload brought its offset back for
 * free, so the ten-second auto-refresh cost a reader nothing. This reloads the page and measures that the
 * offset came back — the first half.
 */
it('brings a table back to where it was being read after a reload', function (): void {
    /** @var AdminDashboardBrowserTestCase $this */
    visit('/firefly/beans?size=200')
        ->assertScript(<<<'JS'
            (() => {
                const wrapper = document.querySelector('.tw');
                wrapper.scrollTop = 600;
                return wrapper.scrollTop > 100 ? true : 'the wrapper did not scroll: ' + wrapper.scrollTop;
            })()
            JS, true)
        ->refresh()
        ->assertScript(<<<'JS'
            (() => {
                const wrapper = document.querySelector('.tw');
                return wrapper.scrollTop > 100 ? true : 'the reload lost the reading position: ' + wrapper.scrollTop;
            })()
            JS, true)
        ->assertNoJavaScriptErrors();
});

/**
 * The second half, and the one the reviewer of the first commit asked for: a plain navigation does NOT
 * restore. Arriving at Beans from the sidebar an hour later and landing in the middle of a table — with the
 * document itself at the top, and nothing on screen to say why — is a defect, so the restore is applied only
 * on the navigation types where the browser would have restored the document's own offset. The saved entry
 * is still in `sessionStorage` when this runs: the first visit scrolled the same URL and left on `pagehide`.
 */
it('opens a table at the top when the reader navigates to it rather than reloading', function (): void {
    /** @var AdminDashboardBrowserTestCase $this */
    visit('/firefly/beans?size=200')
        ->assertScript("document.querySelector('.tw').scrollTop = 600, document.querySelector('.tw').scrollTop > 100", true)
        ->navigate('/firefly/mappings')
        ->navigate('/firefly/beans?size=200')
        ->assertScript("performance.getEntriesByType('navigation')[0].type", 'navigate')
        ->assertScript("document.querySelector('.tw').scrollTop", 0)
        ->assertNoJavaScriptErrors();
});
