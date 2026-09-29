<?php

declare(strict_types=1);

use Firefly\Tests\Browser\Support\BrowserTestCase;

pest()->extend(BrowserTestCase::class);

/**
 * True when every rendered method name sits inside the trace panel rather than being cut off at its edge.
 *
 * THE CHECK IS GEOMETRIC BECAUSE THE BUG WAS. A frame's `.fn` — `->handleStatefulRequest`, the token that
 * tells sixty `Illuminate\…` rows apart — was `flex:none` in a row that had to shrink, so it kept its full
 * WIDTH inside a box too narrow for it, never triggered its own `text-overflow`, and had its glyphs cut by
 * `.panel{overflow:hidden}` instead: measured at 375px, 34 of 35 rows painted up to 138px beyond the panel
 * and `->whereHasMorphRelationship` arrived as `->wh`. No `assertSee` can see that. Text content is
 * identical whether a span is drawn whole or clipped in half, so the promise is asserted as a bounding box.
 *
 * The dependency set is opened for the measurement and put back as it was, so the screenshot taken after
 * this still shows the page as a reader first meets it.
 */
const TRACE_CALLS_INSIDE_PANEL = <<<'JS'
    function () {
        const deps = document.querySelector('details.deps');
        const was = deps !== null && deps.open;
        if (deps !== null) { deps.open = true; }
        const panel = document.querySelector('section.panel.trace').getBoundingClientRect();
        const calls = Array.from(document.querySelectorAll('.frames .fn'));
        const ok = calls.length > 3 && calls.every(el => el.getBoundingClientRect().right <= panel.right);
        if (deps !== null) { deps.open = was; }
        return ok;
    }
    JS;

it('renders a routing miss as the framework\'s 404 page with the trace', function (): void {
    /** @var BrowserTestCase $this */
    visit('/does-not-exist')
        ->assertTitleContains('404 Not Found')
        ->assertSee('404')
        ->assertSee('RESOURCE_NOT_FOUND')
        // With the trace on the page prints the ORIGINAL throwable's message (the router's own sentence),
        // not the person-facing one the production page and problem+json use.
        ->assertSee('The route does-not-exist could not be found.')
        ->assertSee('NotFoundHttpException')
        ->assertSee('Stack trace')
        ->assertSeeIn('dl.facts', 'Reference')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'error-404-debug');
});

it('renders a domain not-found with the throw site open', function (): void {
    /** @var BrowserTestCase $this */
    visit('/orders/999999')
        ->assertSee('404')
        ->assertSee('ORDER_NOT_FOUND')
        ->assertSee('That order does not exist.')
        ->assertSee('ResourceNotFoundException')
        // WAVE UX-E: a frame row prints its path as TWO spans — a directory that may be ellipsised when
        // the row runs out of width, and a file name that never may. `assertSee('app/Orders/OrderService.php')`
        // only ever matched because Playwright concatenates sibling text with no separator; it asserted a
        // string the document no longer contains anywhere. Both halves are named instead, so the assertion
        // says what the page actually promises and fails loudly if either span is dropped.
        //
        // THE DIRECTORY IS MATCHED BY ITS TAIL, not from the project root, because the harness's project
        // root is not a created application's. The skeleton is served from `<monorepo>/skeleton/app/…`, and
        // SourcePaths derives its root from the outermost `vendor/` segment — the monorepo — so the page
        // honestly prints `skeleton/app/Orders/` here and `app/Orders/` in a created project. Anchoring the
        // assertion at `app/Orders/</span>` pins what this test is actually about — that the two spans are
        // adjacent and that the file name is whole and its own — in both layouts.
        ->assertSee('OrderService.php')
        ->assertSourceHas('app/Orders/</span><span class="base">OrderService.php</span>')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'error-404-domain-debug');
});

it('renders a 405 naming the verb the address accepts', function (): void {
    /** @var BrowserTestCase $this */
    visit('/browser-fixture/submit')
        ->assertSee('405')
        ->assertSee('METHOD_NOT_ALLOWED')
        // The router's own sentence, shown verbatim because the trace is on.
        ->assertSee('Supported methods: POST')
        ->assertSee('MethodNotAllowedHttpException')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'error-405-debug');
});

it('renders a 500 with the cause chain, on a page whose height is bounded', function (): void {
    /** @var BrowserTestCase $this */
    visit('/browser-fixture/boom')
        ->assertSee('500')
        ->assertSee('INTERNAL_ERROR')
        ->assertSee('The fixture failed on purpose.')
        ->assertSee('Caused by')
        ->assertSeeIn('.chain', 'RuntimeException')
        ->assertSeeIn('.chain', 'the inner cause')
        ->assertSeeIn('dl.facts', 'Reference')
        // Your frames are the list; a hundred-odd dependency frames are one closed disclosure below them.
        ->assertSee('frames in your dependencies')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'error-500-debug');

    // THE MEASUREMENT. This page was 10,108 pixels tall — not because frames were expanded (exactly two
    // were) but because ErrorReport::shorten() never stripped the base path, so all 104 rows printed an
    // absolute path and wrapped at an 87-pixel pitch. The assertion is the pixel count of the PNG the
    // author looks at, because that is the thing that was wrong.
    $shot = getimagesize(__DIR__.'/Screenshots/error-500-debug.png');

    expect($shot)->toBeArray()
        ->and($shot[1])->toBeLessThan(3000)
        ->and($shot[1])->toBeGreaterThan(400);
});

it('renders the 404 and the 500 in dark mode', function (): void {
    /** @var BrowserTestCase $this */
    visit('/does-not-exist')->inDarkMode()->assertSee('404')->assertNoJavaScriptErrors()->screenshot(filename: 'error-404-debug-dark');
    visit('/browser-fixture/boom')->inDarkMode()->assertSee('500')->assertNoJavaScriptErrors()->screenshot(filename: 'error-500-debug-dark');
});

it('renders the 404 and the 500 at phone width, still bounded', function (): void {
    /** @var BrowserTestCase $this */
    visit('/does-not-exist')->on()->mobile()->assertSee('404')->assertSee('RESOURCE_NOT_FOUND')->assertNoJavaScriptErrors()->screenshot(filename: 'error-404-debug-mobile');
    // A phone is where the row runs out of width first, so it is where the method name is pinned: the
    // `.fn` of every frame has to be inside the panel, not cut off against it.
    visit('/browser-fixture/boom')->on()->mobile()->assertSee('500')->assertSee('Caused by')->assertScript(TRACE_CALLS_INSIDE_PANEL)->assertNoJavaScriptErrors()->screenshot(filename: 'error-500-debug-mobile');

    $shot = getimagesize(__DIR__.'/Screenshots/error-500-debug-mobile.png');

    expect($shot)->toBeArray()->and($shot[1])->toBeLessThan(4000);
});

it('draws each frame on one line, your code first, dependencies behind one disclosure', function (): void {
    /** @var BrowserTestCase $this */
    $page = visit('/browser-fixture/boom');

    $page->assertSee('Stack trace')
        ->assertSee('in your code')
        ->assertSee('frames in your dependencies')
        // The whole point of shortening: the trace names paths relative to the project, not /Users/…
        ->assertSourceHas('<span class="base">')
        ->assertSourceHas('<span class="dir">')
        ->assertSourceMissing('<span class="dir">/Users/')
        // HTML's own exclusive accordion: opening one frame closes the others, with no JavaScript.
        ->assertSourceHas('<details name="firefly-frame"')
        ->assertNoJavaScriptErrors();
});
