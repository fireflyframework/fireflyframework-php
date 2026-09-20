<?php

declare(strict_types=1);

use Firefly\Testing\FireflyTestCase;
use Illuminate\Support\Facades\Route;

pest()->extend(FireflyTestCase::class);

/**
 * The smallest possible proof that the toolchain works end to end: a route defined in the test, served
 * by the plugin's in-process server, rendered by a real Chromium, asserted on, and screenshotted.
 * Superseded by the skeleton-backed suite; deleted once tests/Browser/Support exists.
 */
it('drives a real browser against the in-process application', function (): void {
    Route::get('/browser-smoke', static fn (): string => '<!DOCTYPE html><html><body><h1 id="smoke">LaraFly browser harness</h1></body></html>');

    visit('/browser-smoke')
        ->assertSee('LaraFly browser harness')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'harness-smoke');

    expect(is_file(__DIR__.'/Screenshots/harness-smoke.png'))->toBeTrue();
});
