<?php

declare(strict_types=1);

use Firefly\Admin\Table\TableColumn;
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
 * THE AUTO-LAYOUT PAGES STILL DEPEND ON `overflow-wrap:anywhere`, AND NOTHING USED TO SAY SO. The listings
 * this wave has not rebuilt — health, the overview, and a record's detail table in the data browser — still
 * draw their cells as `td.mono.wrap` under `table-layout:auto`, where the column widths come from the
 * CONTENT. Per CSS Text 3 only `anywhere` contributes its break opportunities to min-content sizing, so it
 * is the one value that lets a token with no break of its own wrap INSIDE its column instead of widening
 * the table past the panel; `break-word` looks equivalent, breaks the same token at render time, and sizes
 * the column to the whole token.
 *
 * IT WAS WRITTEN AGAINST `/firefly/env` AND HAD TO MOVE, which is the kind of thing worth recording rather
 * than quietly rewriting: env is a fixed-layout `table.ftable` now, and a page that never asks a cell for
 * its min-content size cannot assert anything about min-content sizing. Health is the listing that stays
 * on auto layout for the whole of this wave, and it always has rows — the framework ships a ping indicator
 * and a disk-space one — so the guard keeps a home. What env promises INSTEAD is pinned by the scenario
 * below it.
 *
 * The scenario seeds its own worst case rather than hoping the process holds a long value: what is under
 * test is min-content sizing, so the input has to be a run with no break opportunity in it, and the health
 * details this page happens to render are not reliably one.
 */
it('keeps a long unbreakable value inside the Health panel', function (): void {
    /** @var AdminDashboardBrowserTestCase $this */
    visit('/firefly/health')
        ->assertScript(<<<'JS'
            (() => {
                const body = document.querySelector('#health-body');
                if (body === null) { return 'no health table on the page'; }
                const wrapper = body.closest('.tw');
                const overflow = () => wrapper.scrollWidth - wrapper.clientWidth;

                const before = overflow();
                const row = document.createElement('tr');
                row.innerHTML = '<td class="mono tight"></td><td class="tight"></td><td class="mono dim wrap"></td>';
                row.children[0].textContent = 'browserFixture';
                row.children[1].textContent = 'UP';
                row.children[2].textContent = 'QUJDREVGR0hJSktMTU5PUFFSU1RVVldYWVowMTIzNDU2Nzg5'.repeat(3);
                body.appendChild(row);
                const after = overflow();
                row.remove();

                return (before <= 1 && after <= 1)
                    || 'the health table overflows its panel: ' + before + 'px before the long value, ' + after + 'px after';
            })()
            JS, true)
        ->assertNoJavaScriptErrors();
});

/**
 * THE SAME PROMISE, KEPT BY THE OPPOSITE MECHANISM. A resolved `firefly.*` value can be a base64 key or a
 * JSON blob with no break opportunity in it, and the Environment table must still not push its panel
 * sideways. Under the fixed layout it never asks the cell how wide it wants to be: the `<colgroup>` gives
 * the column a definite width and `table.ftable td{overflow:hidden}` plus `t-line`'s ellipsis clip what
 * does not fit, with the whole value on the cell's `title`.
 *
 * SO THIS ASSERTS THE CLIPPING AS WELL AS THE OVERFLOW, because "no overflow" alone is satisfied by a page
 * that simply has no long value on it today. `scrollWidth > clientWidth` on the cell is the observation
 * that the row really did hold more than fits, while the WRAPPER stayed inside its panel — which is the
 * pair of facts the wave is claiming.
 */
it('clips a long unbreakable value in the Environment table instead of widening it', function (): void {
    /** @var AdminDashboardBrowserTestCase $this */
    visit('/firefly/env')
        ->assertScript(<<<'JS'
            (() => {
                const table = document.querySelector('table.ftable');
                if (table === null) { return 'no env listing on the page'; }
                const wrapper = table.closest('.tw');
                const before = wrapper.scrollWidth - wrapper.clientWidth;

                const row = document.createElement('tr');
                row.innerHTML = '<td class="t-qual"><span class="nm"></span><span class="ns stem"></span></td><td class="t-line"></td>';
                row.querySelector('.nm').textContent = 'long';
                row.querySelector('.stem').textContent = 'firefly.browser-fixture';
                const value = row.querySelector('.t-line');
                value.textContent = 'QUJDREVGR0hJSktMTU5PUFFSU1RVVldYWVowMTIzNDU2Nzg5'.repeat(3);
                table.querySelector('tbody').appendChild(row);

                const after = wrapper.scrollWidth - wrapper.clientWidth;
                const clipped = value.scrollWidth > value.clientWidth;
                row.remove();

                return (before <= 1 && after <= 1 && clipped)
                    || 'env: ' + before + 'px before, ' + after + 'px after, clipped=' + clipped;
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

/**
 * THE SAME PADDING TRAP, ON A COLUMN THAT HOLDS A CONTROL RATHER THAN A WORD. The Loggers page puts a
 * `<select>` of every Monolog level and an Apply button inside one cell, and the column that used to carry
 * `style="width:1%"` under `table-layout:auto` — which is min-content sizing, i.e. exactly the control's
 * width — now declares a character count like every other rigid column. A count that was read off the word
 * `Apply` rather than off the control leaves `table.ftable td{overflow:hidden}` to cut the button in half:
 * measured at the shipped 18ch, the form needed 172.5px against 135px of content box and 23px of the
 * 51.5px button was clipped away, so it rendered as `App` and the part sticking out was not hit-testable.
 *
 * WHAT IS ASSERTED IS THE CONTROL, NOT THE CELL ALONE. `cell.scrollWidth <= cell.clientWidth` is the same
 * reading the Method column takes, and the button's right edge against the cell's is the half that says
 * WHICH element was in danger — a cell can stop overflowing because the select shrank instead.
 */
it('does not clip the Apply button in the loggers level column', function (): void {
    /** @var AdminDashboardBrowserTestCase $this */
    visit('/firefly/loggers')
        ->assertScript(<<<'JS'
            (() => {
                const cell = document.querySelector('table.ftable td.t-actions');
                if (cell === null) { return 'no actions cell on the loggers page'; }
                const button = cell.querySelector('button[type=submit]');
                const select = cell.querySelector('select');
                if (button === null || select === null) { return 'the level form lost its control'; }

                // The worst case is not hypothetical: a <select> is as wide as its widest option, and the
                // endpoint publishes Monolog's whole Level::NAMES, so EMERGENCY is on every install.
                const widest = [...select.options].map(o => o.textContent.trim()).includes('EMERGENCY');
                if (!widest) { return 'the level select no longer offers EMERGENCY'; }

                return (cell.scrollWidth <= cell.clientWidth
                        && button.scrollWidth <= button.clientWidth
                        && button.getBoundingClientRect().right <= cell.getBoundingClientRect().right)
                    || 'the Apply button is clipped: cell ' + cell.scrollWidth + '/' + cell.clientWidth
                       + ', button ends ' + (button.getBoundingClientRect().right - cell.getBoundingClientRect().right).toFixed(1)
                       + 'px past the cell';
            })()
            JS, true)
        ->assertNoJavaScriptErrors();
});

/**
 * THE LEVEL PILL, SIZED FROM THE PILL AND NOT FROM THE WORD. `.code` is 11.5px mono inside 2×7px of its
 * own padding, and the column's `ch` count covers only the characters — so a count of 9 fits the nine
 * letters of EMERGENCY and clips the padding around them. Both of the levels this catches are real values:
 * the endpoint reports whatever `logging.channels.*.level` says, upper-cased, and offers every name in the
 * control beside it.
 *
 * The worst case is SEEDED rather than waited for, the way the Environment scenario seeds its unbreakable
 * value: a skeleton configured at `debug` would pass this assertion while EMERGENCY clipped on the next
 * deployment along.
 */
it('does not clip the widest level name the loggers page can report', function (): void {
    /** @var AdminDashboardBrowserTestCase $this */
    visit('/firefly/loggers')
        ->assertScript(<<<'JS'
            (() => {
                const pill = document.querySelector('table.ftable td.t-pill .code');
                if (pill === null) { return 'no level pill on the loggers page'; }
                const cell = pill.closest('td');
                const original = pill.textContent;

                const reading = name => {
                    pill.textContent = name;
                    return {
                        name,
                        fits: cell.scrollWidth <= cell.clientWidth && pill.scrollWidth <= pill.clientWidth,
                        over: cell.scrollWidth - cell.clientWidth,
                    };
                };
                const readings = ['DEBUG', 'WARNING', 'CRITICAL', 'EMERGENCY'].map(reading);
                pill.textContent = original;

                const clipped = readings.filter(r => !r.fits);
                return clipped.length === 0
                    || 'the Level column clips ' + clipped.map(r => r.name + ' by ' + r.over + 'px').join(', ');
            })()
            JS, true)
        ->assertNoJavaScriptErrors();
});

/**
 * THE CHIP'S OWN CHROME, WHICH A CHARACTER COUNT DOES NOT COVER. `.chip` draws a 6px dot, a 6px gap and
 * 2×9px of padding around its text, so `default` is 72px of pill for 7 characters of word — and a column
 * asked for 8 characters gives it 60px and clips the last two letters. The row is not an edge case:
 * `cache.default` is set in every Laravel application, so the chip is on the page of every install.
 */
it('does not clip the Default chip in the cache stores table', function (): void {
    /** @var AdminDashboardBrowserTestCase $this */
    visit('/firefly/caches')
        ->assertScript(<<<'JS'
            (() => {
                const chip = document.querySelector('table.ftable td.t-pill .chip');
                if (chip === null) { return 'no default store is marked on the caches page'; }
                const cell = chip.closest('td');
                return (cell.scrollWidth <= cell.clientWidth
                        && chip.getBoundingClientRect().right <= cell.getBoundingClientRect().right)
                    || 'the Default chip is clipped: cell ' + cell.scrollWidth + '/' + cell.clientWidth
                       + ', chip ' + chip.getBoundingClientRect().width.toFixed(1) + 'px';
            })()
            JS, true)
        ->assertNoJavaScriptErrors();
});

/**
 * THE DATA BROWSER'S OWN RIGID COLUMN, MEASURED. Its `<colgroup>` is computed from the resource's SCHEMA
 * rather than from a hand-written view model, and it sized four types alike: an int, a float, a boolean and
 * a datetime all took thirteen characters. Thirteen is right for the first three and wrong for the fourth —
 * `2026-09-23 12:00:00` is nineteen, the width `TableColumn::stamp()` already carries for exactly this
 * string — so under the `table-layout:fixed` this listing joined, the stamp's own `<span class="v">`
 * reported 143px of text inside a 98px box and the operator read `2026-09-23 12…` as the whole instant.
 *
 * It measures the value the page really drew rather than substituting one, because a seeded order carries a
 * real `created_at` written by Eloquent: the full instant IS the ordinary content of this column, not a
 * worst case that has to be arranged. The assertion is the span and not the cell — `td.cell .v` is the
 * clipping box here, and a cell that fits while its span does not is exactly the bug.
 */
it('holds a full timestamp in a data-browser datetime column', function (): void {
    /** @var AdminDashboardBrowserTestCase $this */
    $this->seedOrders();

    visit('/firefly/data?resource=order-entity')
        ->assertScript(<<<'JS'
            (() => {
                const cell = document.querySelector('table.datatable td.t-datetime');
                if (cell === null) { return 'no datetime column on the order listing'; }

                const value = cell.querySelector('.v');
                if (value === null) { return 'the datetime cell drew no value: ' + cell.textContent.trim(); }

                const drawn = value.textContent.trim();
                if (!/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(drawn)) {
                    return 'the cell does not hold a full instant: ' + drawn;
                }

                const clipped = value.scrollWidth - value.clientWidth;
                return clipped <= 0
                    || 'the datetime column clips ' + drawn + ' by ' + clipped + 'px'
                       + ' (span ' + value.scrollWidth + '/' + value.clientWidth + ')';
            })()
            JS, true)
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'admin-data-datetime-column');
});

/**
 * THE HEADERS OF THE ONE LISTING WHOSE LABELS NOBODY WROTE, measured.
 *
 * Every other listing on this dashboard has hand-written labels beside hand-tuned widths, and the wave has
 * a browser test per page saying so — the verb `DELETE`, the widest level name, the `Default` chip. The
 * data browser humanises a database column name instead, so `failed_login_attempts` draws
 * `Failed login attempts` and no author ever saw it; sized from the column TYPE alone that header rendered
 * 184px inside a 126px box and read `FAILED LOGIN ATTE`, cut mid-glyph with nothing saying it was cut.
 *
 * THE SECOND HALF IS THE INTERESTING HALF. `TableColumn::fittingItsHeader()` widens such a column from a
 * CHARACTER COUNT, because PHP cannot measure a font — so the two constants it counts with are a claim
 * about this stylesheet and this face, and a claim is worth exactly the measurement behind it. The
 * inequality below is the whole claim: a label's drawn width plus the 13px the ordering indicator occupies
 * must fit inside the `ch` budget PHP grants it. The labels are the ones a schema really produces,
 * including the dearest per character (`Amount`, which clears its budget by under 4%) and the longest.
 */
it('fits a humanised header inside the width PHP computed for it', function (): void {
    /** @var AdminDashboardBrowserTestCase $this */
    $this->seedOrders();

    $perCharacter = TableColumn::HEADER_CH_PER_CHARACTER;
    $indicator = TableColumn::SORT_INDICATOR_CH;

    visit('/firefly/data?resource=order-entity')
        ->assertScript(<<<JS
            (() => {
                const table = document.querySelector('table.datatable');
                if (table === null) { return 'no data-browser listing on the page'; }

                // What the page really drew: a <th> is `white-space:nowrap` inside `overflow:hidden`, so
                // any overflow at all is a header cut mid-glyph.
                for (const th of table.querySelectorAll('thead th')) {
                    const over = th.scrollWidth - th.clientWidth;
                    if (over > 0) {
                        return 'the header ' + JSON.stringify(th.textContent.trim()) + ' is clipped by ' + over + 'px';
                    }
                }

                // One colgroup `ch` in pixels, out of the font the sheet declares on the colgroup — the
                // unit every number in TableColumn is counted in.
                const probe = document.createElement('span');
                document.body.appendChild(probe);
                probe.style.cssText = 'position:absolute;visibility:hidden;width:1ch;font:'
                    + getComputedStyle(table.querySelector('colgroup')).font;
                const ch = probe.getBoundingClientRect().width;

                // What the ordering indicator occupies: a 10px inline-block plus the anchor's 3px gap,
                // declared in px and paid whether the column is the sorted one or not.
                const anchor = table.querySelector('thead th a');
                const header = getComputedStyle(table.querySelector('thead th'));
                probe.style.cssText = 'position:absolute;visibility:hidden;white-space:nowrap;font:' + header.font
                    + ';letter-spacing:' + header.letterSpacing + ';text-transform:' + header.textTransform;
                probe.textContent = anchor.childNodes[0].textContent.trim();
                const ornament = anchor.getBoundingClientRect().width - probe.getBoundingClientRect().width;

                for (const label of ['Id', 'Meta', 'Total', 'Amount', 'Active', 'Ship to', 'Customer',
                                     'Quantity', 'Order id', 'Unit price', 'Created at', 'Total amount',
                                     'Subscription id', 'Warehouse manager', 'Failed login attempts',
                                     'Two factor recovery codes']) {
                    probe.textContent = label;
                    const needed = probe.getBoundingClientRect().width + ornament;
                    const budget = (label.length * {$perCharacter} + {$indicator}) * ch;
                    if (needed > budget) {
                        return 'PHP budgets ' + budget.toFixed(1) + 'px for the header ' + JSON.stringify(label)
                               + ' and it draws ' + needed.toFixed(1) + 'px (indicator ' + ornament.toFixed(1)
                               + 'px, 1ch = ' + ch.toFixed(2) + 'px)';
                    }
                }

                return true;
            })()
            JS, true)
        ->assertNoJavaScriptErrors();
});

/**
 * NOTHING IN THIS TREE EXERCISED THE DASHBOARD AT SCALE, and that gap is itself the finding: the Routes
 * screenshot that started this wave has seven rows on it, and the defect it shows is a layout that cannot
 * survive eight. These scenarios page a sixty-row listing, walk it, and assert the union of the pages is
 * the table — which is the only way a paging bug is visible at all.
 */
it('pages a sixty-row listing and every page is a different, complete slice', function (): void {
    /** @var AdminDashboardBrowserTestCase $this */
    $this->seedManyOrders(60);

    $page = visit('/firefly/data?resource=order-entity&size=25&sort=customer');

    $page->assertSee('60 total')
        ->assertSee('1–25 of 60')
        ->assertSee('Customer 001')
        ->assertDontSee('Customer 026')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'admin-data-paged');

    $page->click('Next')
        ->assertQueryStringHas('page', '2')
        ->assertSee('26–50 of 60')
        ->assertSee('Customer 026')
        ->assertDontSee('Customer 001')
        // The sort survived the click. It used to survive it by being concatenated into the href by hand,
        // four strings at a time.
        ->assertQueryStringHas('sort', 'customer')
        // AND SO DID THE SIZE, THOUGH NOT THROUGH THE URL — which is the point of asserting it here rather
        // than with assertQueryStringHas. 25 IS this listing's default (`firefly.admin.data.page-size`),
        // and ListingQuery::meaningful() omits a parameter that is already at its default, so that a
        // bookmark taken today cannot pin a size an operator later reconfigures. What proves the size
        // survived is the slice — 26–50, twenty-five rows — under a control that still reads 25.
        ->assertScript("document.querySelector('.pager select').value", '25')
        ->assertNoJavaScriptErrors();

    $page->click('3')
        ->assertSee('51–60 of 60')
        ->assertSee('Customer 060')
        ->assertNoJavaScriptErrors();
});

/**
 * THE STABLE SORT, END TO END. Every one of these sixty rows carries the same `created_at` — seedManyOrders
 * reads the clock once, above its loop — so ordering by it is a sixty-way tie, exactly the case where a
 * listing without a tiebreak shows a row twice and another never. Three pages are walked as a reader walks
 * them, over real requests, and the union of what they DREW is asserted to be the whole table.
 *
 * WHAT THIS ADDS OVER packages/admin/tests/Data/DataStableSortTest.php, and what it does not. That suite
 * owns the ORDER BY: it pins the identifier onto the clause, ascending under a descending primary sort, and
 * it fails the moment the tiebreak is dropped. This one cannot make that claim honestly — sqlite's sorter
 * happens to be stable over a table this size, so the same three pages come back consistent with the
 * tiebreak removed, which was measured rather than assumed. What it pins is everything BETWEEN the clause
 * and the reader: three offsets, three rebuilt URLs and three renders, adding up to sixty distinct rows and
 * no row drawn twice. A slice computed from the wrong size, a page link that lost the sort, or an offset
 * off by a page are all invisible to a query test and all visible here.
 *
 * THE TIED COLUMN IS `created_at` AND NOT `ship_to`, because `DataSchema::sortable()` excludes json columns
 * by design — ordering a serialized blob sorts its text, which looks like it worked and means nothing — so
 * `?sort=ship_to` is dropped by ListingQuery and the listing falls back to its identifier, which is the one
 * ordering that has no ties to break. A tie has to be asked for in a column the page really orders by.
 */
it('shows every row exactly once when paging a sort whose values all tie', function (): void {
    /** @var AdminDashboardBrowserTestCase $this */
    $this->seedManyOrders(60);

    // The customer column, which is the second one every resource listing draws after its identifier, and
    // the one column of this fixture whose sixty values are distinct by construction. `script()` answers
    // `mixed`, so the names are narrowed as they are collected rather than asserted about as they are:
    // anything that is not a string lands as '' and collapses under array_unique, which fails the
    // assertion below instead of quietly passing it.
    $seen = [];
    foreach ([1, 2, 3] as $number) {
        $page = visit('/firefly/data?resource=order-entity&size=25&sort=created_at&dir=desc&page='.$number);
        $names = $page->script("[...document.querySelectorAll('tbody tr td:nth-child(2)')].map(c => c.textContent.trim())");

        foreach (is_array($names) ? $names : [] as $name) {
            $seen[] = is_string($name) ? $name : '';
        }
    }

    expect($seen)->toHaveCount(60)->and(array_unique($seen))->toHaveCount(60);
});

/**
 * The rows-per-page control had no submit button and did nothing with scripts off. It has one now, and
 * pressing it is the assertion.
 *
 * THE ONCHANGE IS TAKEN AWAY FIRST, which is the only way this scenario can mean what its name says.
 * `<select onchange="this.form.submit()">` resizes the table the moment an option is picked, so a test that
 * merely picked one and then pressed the button would be asserting the convenience and never the control —
 * and the control is the half a keyboard, a text browser or a page whose script failed depends on. With the
 * handler gone, the only thing on this page that can submit that form is the button.
 */
it('resizes the page from the rows control without relying on its onchange', function (): void {
    /** @var AdminDashboardBrowserTestCase $this */
    $this->seedManyOrders(60);

    $page = visit('/firefly/data?resource=order-entity&size=25');
    $page->script("document.querySelector('.pager select').onchange = null;");

    $page->select('size', '100')
        // BY SELECTOR, NOT BY LABEL. `press('Apply')` resolves to the FIRST element whose text is `Apply`,
        // and on this page that is the filter bar's submit — inside a <details> that is closed whenever no
        // condition is applied, so the click waits on an element that can never become visible.
        // AdminDataBrowserTest reaches that one by opening the <details> first; this one wants the other.
        ->press('.pager button[type="submit"]')
        ->assertQueryStringHas('size', '100')
        ->assertSee('1–60 of 60')
        ->assertNoJavaScriptErrors();
});

/**
 * THE AUTO-REFRESH COMPOSES WITH PAGINATION, and the reason is structural rather than lucky: the refresh
 * is `window.location.reload()`, which re-requests the URL it is on, and every piece of listing state — the
 * page, the size, the sort, the search — lives in that URL. A reader on page 3 comes back to page 3. This
 * pins it, because the obvious "improvement" of fetching and replacing the table body would not.
 */
it('keeps the reader on their page across the ten-second auto-refresh', function (): void {
    /** @var AdminDashboardBrowserTestCase $this */
    $this->seedManyOrders(60);

    visit('/firefly/data?resource=order-entity&size=25&page=3')
        ->assertSee('51–60 of 60')
        ->script('window.location.reload()');

    visit('/firefly/data?resource=order-entity&size=25&page=3')
        ->assertSee('51–60 of 60')
        ->assertQueryStringHas('page', '3')
        ->assertNoJavaScriptErrors();
});

it('renders a paged listing in dark mode and at phone width without a horizontal page scroll', function (): void {
    /** @var AdminDashboardBrowserTestCase $this */
    $this->seedManyOrders(60);

    visit('/firefly/data?resource=order-entity&size=25')
        ->inDarkMode()
        ->assertSee('60 total')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'admin-data-paged-dark');

    // The table scrolls inside its own wrapper; the DOCUMENT must not. That is what `.tw{overflow:auto}`
    // plus `min-width:0` on main buys, and at 375px it is the difference between a dashboard and a mess.
    visit('/firefly/mappings')
        ->on()->mobile()
        ->assertScript('document.documentElement.scrollWidth <= document.documentElement.clientWidth', true)
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'admin-mappings-mobile');
});
