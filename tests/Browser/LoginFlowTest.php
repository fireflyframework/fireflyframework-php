<?php

declare(strict_types=1);

use Firefly\Tests\Browser\Support\SignedInBrowserTestCase;

pest()->extend(SignedInBrowserTestCase::class);

/**
 * The framework's form login, as a person meets it in Chromium: refused at /orders and sent to the login page,
 * a wrong password answered on the page, the right one honouring the saved request, POST /logout ending the
 * session, a signed-in non-admin refused with the 403 page, and an API path still answered as JSON.
 *
 * ONE PAGE PER FLOW. Every visit() opens a NEW Playwright browser context — a fresh cookie jar — so a flow
 * that must keep its session drives one page object through fill/click/navigate; a second visit() would be
 * a different, anonymous browser. That is also why the "still anonymous after logout" proof navigates the
 * same page rather than visiting again.
 */
it('sends an anonymous browser from a protected page to the framework\'s login page', function (): void {
    /** @var SignedInBrowserTestCase $this */
    visit('/orders')
        ->assertPathIs('/login')
        ->assertTitleContains('Sign in')
        ->assertSee('Sign in')
        ->assertPresent('form[action="/login"]')
        ->assertPresent('input[name="_token"]')
        ->assertPresent('input[name="username"]')
        ->assertPresent('input[name="password"]')
        ->assertDontSee('Those credentials did not work.')
        ->assertDontSee('You have signed out.')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'login');
});

it('renders the login page in dark mode and at phone width', function (): void {
    /** @var SignedInBrowserTestCase $this */
    visit('/orders')->inDarkMode()->assertPathIs('/login')->assertSee('Sign in')->assertNoJavaScriptErrors()->screenshot(filename: 'login-dark');
    visit('/orders')->on()->mobile()->assertPathIs('/login')->assertSee('Sign in')->assertNoJavaScriptErrors()->screenshot(filename: 'login-mobile');
});
