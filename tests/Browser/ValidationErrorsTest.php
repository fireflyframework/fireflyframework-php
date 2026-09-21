<?php

declare(strict_types=1);

use Firefly\Cli\Tests\Support\SkeletonApp;
use Firefly\Tests\Browser\Support\BrowserTestCase;
use Illuminate\Support\Facades\Route;

pest()->extend(BrowserTestCase::class);

/**
 * The skeleton's POST /orders, driven from a page. A browser cannot post JSON from a plain <form>, so the
 * fixture page carries the body and a button whose handler fetch()es /orders — through the plugin's
 * in-process server, the very pipeline an API client hits — and writes every entry of the problem document's
 * `errors` list into the DOM as `field — message [constraint]`. What the page shows is therefore exactly
 * what a client parses: the field path as it was sent (`lines[1].sku`, not Laravel's `lines.1.sku`), the
 * constraint's own sentence, and the constraint's name.
 */
beforeEach(function (): void {
    /** @var BrowserTestCase $this */
    $body = SkeletonApp::orderBody([
        'shipTo' => ['street' => '12 Analytical Way', 'city' => 'London', 'postcode' => '', 'country' => 'GB'],
        'lines' => [
            ['sku' => 'WIDGET-1', 'quantity' => 2, 'unitPrice' => 9.5],
            ['sku' => 'bad sku!', 'quantity' => 0, 'unitPrice' => 3.25],
        ],
    ]);
    $json = json_encode($body, JSON_THROW_ON_ERROR);

    Route::get('/browser-fixture/order-form', static fn (): string => <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head><meta charset="utf-8"><title>Order form fixture</title></head>
        <body>
        <h1>Order form fixture</h1>
        <button id="place" type="button">Place order</button>
        <p id="outcome"></p>
        <ul id="errors"></ul>
        <script>
        const body = {$json};
        document.getElementById('place').addEventListener('click', async () => {
            const response = await fetch('/orders', {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
                body: JSON.stringify(body),
            });
            const problem = await response.json();
            document.getElementById('outcome').textContent = response.status + ' ' + problem.code;
            for (const error of problem.errors ?? []) {
                const item = document.createElement('li');
                item.textContent = error.field + ' — ' + error.message + ' [' + error.constraint + ']';
                document.getElementById('errors').appendChild(item);
            }
        });
        </script>
        </body>
        </html>
        HTML);
});

it('shows the element path and the constraint sentence for a bad line, straight from the problem document', function (): void {
    /** @var BrowserTestCase $this */
    visit('/browser-fixture/order-form')
        ->assertSee('Order form fixture')
        ->press('Place order')
        ->wait(1)
        ->assertSee('422 VALIDATION_ERROR')
        ->assertSee('shipTo.postcode — must not be blank [NotBlank]')
        ->assertSee('lines[1].sku — must match "^[A-Z0-9][A-Z0-9-]{2,31}$" [Pattern]')
        ->assertSee('lines[1].quantity — must be greater than 0 [Positive]')
        ->assertDontSee('lines.1.sku')
        ->assertDontSee('field is required')
        ->assertDontSee('UNBINDABLE_BODY')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'validation-errors');
});
