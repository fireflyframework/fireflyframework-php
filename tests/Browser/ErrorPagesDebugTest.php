<?php

declare(strict_types=1);

use Firefly\Tests\Browser\Support\BrowserTestCase;

pest()->extend(BrowserTestCase::class);

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
        ->assertSee('app/Orders/OrderService.php')
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

it('renders a 500 with the cause chain', function (): void {
    /** @var BrowserTestCase $this */
    visit('/browser-fixture/boom')
        ->assertSee('500')
        ->assertSee('INTERNAL_ERROR')
        ->assertSee('The fixture failed on purpose.')
        ->assertSee('Caused by')
        ->assertSeeIn('.chain', 'RuntimeException')
        ->assertSeeIn('.chain', 'the inner cause')
        ->assertSeeIn('dl.facts', 'Reference')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'error-500-debug');
});

it('renders the 404 and the 500 in dark mode', function (): void {
    /** @var BrowserTestCase $this */
    visit('/does-not-exist')->inDarkMode()->assertSee('404')->assertNoJavaScriptErrors()->screenshot(filename: 'error-404-debug-dark');
    visit('/browser-fixture/boom')->inDarkMode()->assertSee('500')->assertNoJavaScriptErrors()->screenshot(filename: 'error-500-debug-dark');
});

it('renders the 404 and the 500 at phone width', function (): void {
    /** @var BrowserTestCase $this */
    visit('/does-not-exist')->on()->mobile()->assertSee('404')->assertSee('RESOURCE_NOT_FOUND')->assertNoJavaScriptErrors()->screenshot(filename: 'error-404-debug-mobile');
    visit('/browser-fixture/boom')->on()->mobile()->assertSee('500')->assertSee('Caused by')->assertNoJavaScriptErrors()->screenshot(filename: 'error-500-debug-mobile');
});
