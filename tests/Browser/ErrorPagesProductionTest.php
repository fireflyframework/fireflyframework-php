<?php

declare(strict_types=1);

use Firefly\Tests\Browser\Support\ProductionBrowserTestCase;

pest()->extend(ProductionBrowserTestCase::class);

it('renders the production 404 with the code and a reassurance, and nothing internal', function (): void {
    /** @var ProductionBrowserTestCase $this */
    visit('/does-not-exist')
        ->assertSee('404')
        ->assertSee('RESOURCE_NOT_FOUND')
        ->assertSee('That page does not exist.')
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
        ->assertSee('That page does not exist.')
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
        ->assertSee('That address does not accept this kind of request.')
        ->assertDontSee('Stack trace')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'error-405-production');
});

it('renders the production 500 with a reference to quote and the cause withheld', function (): void {
    /** @var ProductionBrowserTestCase $this */
    visit('/browser-fixture/boom')
        ->assertSee('500')
        ->assertSee('INTERNAL_ERROR')
        ->assertSee('quote reference')
        ->assertSeeIn('dl.facts', 'Reference')
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
