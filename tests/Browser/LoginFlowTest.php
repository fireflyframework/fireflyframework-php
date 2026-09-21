<?php

declare(strict_types=1);

use Firefly\Tests\Browser\Support\SecuredBrowserTestCase;
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

it('shows the error state for a wrong password, then honours the saved request on the right one', function (): void {
    /** @var SignedInBrowserTestCase $this */
    $page = visit('/orders');

    $page->assertPathIs('/login')
        ->fill('username', SecuredBrowserTestCase::ADMIN)
        ->fill('password', 'not-the-password')
        ->click(SecuredBrowserTestCase::SIGN_IN_BUTTON)
        ->assertPathIs('/login')
        ->assertQueryStringHas('error')
        ->assertSee('Those credentials did not work.')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'login-error');

    // The saved request survives the refused attempt (a failure never consumes it), and the fixation-protected
    // sign-in keeps the session's attributes — so the right password lands on /orders, not on the default URL.
    $page->fill('username', SecuredBrowserTestCase::ADMIN)
        ->fill('password', SecuredBrowserTestCase::ADMIN_PASSWORD)
        ->click(SecuredBrowserTestCase::SIGN_IN_BUTTON)
        ->assertPathIs('/orders')
        ->assertSourceHas('"items"')
        ->assertSourceHas('"total"')
        ->assertDontSee('Sign in')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'login-saved-request');
});

it('signs out through POST /logout, lands on the signed-out notice, and is anonymous again', function (): void {
    /** @var SignedInBrowserTestCase $this */
    $page = visit('/orders');

    $page->fill('username', SecuredBrowserTestCase::ADMIN)
        ->fill('password', SecuredBrowserTestCase::ADMIN_PASSWORD)
        ->click(SecuredBrowserTestCase::SIGN_IN_BUTTON)
        ->assertPathIs('/orders');

    // No framework page renders a logout control (the login page is the only framework page, and /orders is
    // JSON), and LogoutFilter takes POST with the session token only — so the fixture form is submitted.
    $page->navigate(SecuredBrowserTestCase::SIGN_OUT_PATH)
        ->assertSee('Sign-out fixture')
        ->click('#sign-out')
        ->assertPathIs('/login')
        ->assertQueryStringHas('logout')
        ->assertSee('You have signed out.')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'logout');

    // The session was invalidated, not merely emptied: the cookie the browser still holds names nothing.
    $page->navigate('/orders')
        ->assertPathIs('/login')
        ->assertDontSee('You have signed out.')
        ->assertNoJavaScriptErrors();
});

it('signs bob in and answers his saved request with the 403 page', function (): void {
    /** @var SignedInBrowserTestCase $this */
    visit('/orders')
        ->assertPathIs('/login')
        ->fill('username', SecuredBrowserTestCase::USER)
        ->fill('password', SecuredBrowserTestCase::USER_PASSWORD)
        ->click(SecuredBrowserTestCase::SIGN_IN_BUTTON)
        ->assertPathIs('/orders')
        ->assertSee('403')
        ->assertSee('ACCESS_DENIED')
        ->assertSee('Access is denied.')
        ->assertDontSee('Sign in')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'login-user-403');
});

it('still answers an API path with the 401 problem document, never a redirect', function (): void {
    /** @var SignedInBrowserTestCase $this */
    visit('/api/browser-fixture/missing')
        ->assertPathIs('/api/browser-fixture/missing')
        ->assertSourceHas('"status":401')
        ->assertSourceHas('"code":"AUTHENTICATION_FAILED"')
        ->assertSourceMissing('<h1')
        ->assertSourceMissing('Sign in')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'login-api-401-json');
});
