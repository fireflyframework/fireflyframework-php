<?php

declare(strict_types=1);

use Firefly\Tests\Browser\Support\BrowserTestCase;

pest()->extend(BrowserTestCase::class);

it('renders the welcome page with the live route table', function (): void {
    /** @var BrowserTestCase $this */
    visit('/')
        ->assertSee('Hello, LaraFly')
        ->assertSee('Your routes')
        ->assertSee('/orders/{id}')
        ->assertSee('OrderController')
        ->assertSee('GreetingController')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'welcome');
});

it('answers the REST controller with JSON, not a page', function (): void {
    /** @var BrowserTestCase $this */
    visit('/greetings/Ada')
        ->assertSourceHas('Hello, Ada!')
        ->assertSourceMissing('<h1')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'greeting-json');
});

it('serves the OpenAPI viewer, and Swagger UI draws the operations without a JavaScript error', function (): void {
    /** @var BrowserTestCase $this */
    visit('/openapi')
        ->assertPresent('#swagger-ui')
        ->wait(2)
        ->assertSee('/orders/{id}')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'openapi-viewer');
});
