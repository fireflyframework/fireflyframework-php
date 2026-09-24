<?php

declare(strict_types=1);

use Firefly\Tests\Browser\Support\ProductionBrowserTestCase;

pest()->extend(ProductionBrowserTestCase::class);

it('renders the production 404 with the code and a reassurance, and nothing internal', function (): void {
    /** @var ProductionBrowserTestCase $this */
    visit('/does-not-exist')
        ->assertSee('404')
        ->assertSee('RESOURCE_NOT_FOUND')
        // WAVE UX-E: the production lede is the sentence the problem document publishes for the same
        // failure, and for a route that matches nothing that sentence is ProblemMapper::NOTHING_HERE. It
        // used to be the page's own "That page does not exist." — a fourth wording of one error, beside
        // the document's, the log's and the router's.
        ->assertSee('There is nothing at this address.')
        ->assertDontSee('Stack trace')
        ->assertDontSee('NotFoundHttpException')
        ->assertDontSee('APP_DEBUG')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'error-404-production');
});

it('keeps the domain code on the production page but not the throw site', function (): void {
    /** @var ProductionBrowserTestCase $this */
    visit('/orders/999999')
        ->assertSee('ORDER_NOT_FOUND')
        // And here it is the APPLICATION's sentence — OrderService::NOT_FOUND_SENTENCE, the one the
        // skeleton wrote and problem+json has always published as `detail`. The code was already shared
        // between the two surfaces; now the sentence is, which is the whole of wave UX-E's Task 6.
        ->assertSee('That order does not exist.')
        ->assertDontSee('OrderService.php')
        ->assertDontSee('ResourceNotFoundException')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'error-404-domain-production');
});

it('renders the production 405', function (): void {
    /** @var ProductionBrowserTestCase $this */
    visit('/browser-fixture/submit')
        ->assertSee('405')
        ->assertSee('METHOD_NOT_ALLOWED')
        // The verbs, not a shrug. The router named them on its own exception and the problem document has
        // published them as `allowed` all along; the page is the surface that used to throw them away.
        ->assertSee('That address does not accept a GET request. It accepts POST.')
        ->assertDontSee('Stack trace')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'error-405-production');
});

it('renders the production 500 with a reference to quote and the cause withheld', function (): void {
    /** @var ProductionBrowserTestCase $this */
    visit('/browser-fixture/boom')
        ->assertSee('500')
        ->assertSee('INTERNAL_ERROR')
        // The lede points at the Reference cell instead of repeating the id into prose, so the words on
        // the page changed with it; the cell itself is asserted on the line below, as it always was.
        ->assertSee('quote the reference below')
        ->assertSeeIn('dl.facts', 'Reference')
        ->assertSeeIn('.fact-ref', 'Quote this if you report the problem.')
        // The request path (/browser-fixture/boom) is legitimately shown in the facts, so the cause is
        // proved withheld by its class and message, never by the word "boom".
        ->assertDontSee('Caused by')
        ->assertDontSee('RuntimeException')
        ->assertDontSee('the inner cause')
        ->assertDontSee('LogicException')
        ->assertDontSee('The fixture failed on purpose.')
        ->assertDontSee('Stack trace')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'error-500-production');
});

it('reveals the copy button on the production 500, and a click leads somewhere either way', function (): void {
    /** @var ProductionBrowserTestCase $this */
    // THE PAGE'S ONLY SCRIPT, RUN BY A REAL BROWSER rather than grepped out of the markup. The button is
    // emitted `hidden` by ErrorPage::reference() and the ten lines in ErrorPage::clipboard() reveal it only
    // after finding it with `querySelector(".copy")` — so a VISIBLE "Copy" is the one assertion that holds
    // the class on the control against the selector that looks for it. Every other expectation about this
    // control is string containment on ONE of those two sides: rename the class, or the selector, and both
    // markup tests stay green while every production 500 ships a control that is never shown. The plugin
    // serves the app on localhost, which is a secure context, so `navigator.clipboard` is defined and the
    // reveal is the expected outcome here.
    $page = visit('/browser-fixture/boom');

    $page->assertSeeIn('.ref-act', 'Copy')->assertNoJavaScriptErrors();

    // And the click leads somewhere. WHICH arm runs is the browser's business — this headless Chromium
    // refuses `clipboard-write` and takes the rejection arm, a focused desktop browser takes the other one
    // — so the assertion is the thing both arms owe the reader: the label stops saying "Copy". Before the
    // `.catch` arm existed, a refusal left the label exactly as it was, did nothing at all, and wrote an
    // unhandled rejection into the console of the page whose whole job is to be quiet; the JavaScript-error
    // assertion below is what holds that shut, on the very path this browser actually takes.
    $page->click('.copy');

    $label = $page->text('.copy');
    for ($attempt = 0; $attempt < 50 && $label === 'Copy'; $attempt++) {
        usleep(100_000);
        $label = $page->text('.copy');
    }

    expect($label)->toBeIn(['Copied', 'Copy failed']);

    $page->assertNoJavaScriptErrors()->screenshot(filename: 'error-500-production-copy-clicked');
});

it('renders the production 404 and 500 in dark mode and at phone width', function (): void {
    /** @var ProductionBrowserTestCase $this */
    visit('/does-not-exist')->inDarkMode()->assertSee('404')->assertNoJavaScriptErrors()->screenshot(filename: 'error-404-production-dark');
    visit('/browser-fixture/boom')->inDarkMode()->assertSee('500')->assertNoJavaScriptErrors()->screenshot(filename: 'error-500-production-dark');
    visit('/does-not-exist')->on()->mobile()->assertSee('404')->assertNoJavaScriptErrors()->screenshot(filename: 'error-404-production-mobile');
    visit('/browser-fixture/boom')->on()->mobile()->assertSee('500')->assertNoJavaScriptErrors()->screenshot(filename: 'error-500-production-mobile');
});

it('answers an API path with problem+json even though a browser asked', function (): void {
    /** @var ProductionBrowserTestCase $this */
    visit('/api/browser-fixture/missing')
        ->assertSourceHas('"status":404')
        ->assertSourceHas('"code":"RESOURCE_NOT_FOUND"')
        ->assertSourceHas('"traceId"')
        ->assertSourceMissing('<h1')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'error-404-api-json');
});
