<?php

declare(strict_types=1);

use Firefly\Tests\Browser\Support\SecuredBrowserTestCase;

pest()->extend(SecuredBrowserTestCase::class);

/**
 * The 401 and 403 PAGES under deny-by-default URL rules. The fixture's entry point is `problem`, so an
 * anonymous browser is answered with the framework's 401 page rather than sent to sign in (that redirect is
 * LoginFlowTest's subject); the 403 is reached the way a real person reaches it — bob signs in through the
 * framework's own form and is refused as an authenticated non-admin. The `?as=user` stand-in the harness
 * shipped for this scenario is gone.
 */
it('answers an anonymous browser with the 401 page', function (): void {
    /** @var SecuredBrowserTestCase $this */
    visit('/orders')
        ->assertSee('401')
        ->assertSee('AUTHENTICATION_FAILED')
        ->assertSee('Authentication is required to access this resource.')
        ->assertDontSee('Ada Lovelace')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'error-401');
});

it('answers an authenticated non-admin with the 403 page', function (): void {
    /** @var SecuredBrowserTestCase $this */
    $page = visit('/login');

    $page->assertSee('Sign in')
        ->fill('username', SecuredBrowserTestCase::USER)
        ->fill('password', SecuredBrowserTestCase::USER_PASSWORD)
        ->click(SecuredBrowserTestCase::SIGN_IN_BUTTON)
        // Nobody was refused first, so no request was saved: the login lands on default_success_url — `/` —
        // which the `*` rule refuses to a ROLE_USER exactly like every other path.
        ->assertPathIs('/')
        ->assertSee('403');

    $page->navigate('/orders')
        ->assertSee('403')
        ->assertSee('ACCESS_DENIED')
        ->assertSee('Access is denied.')
        ->assertDontSee('Sign in')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'error-403');
});

it('renders the 401 in dark mode and at phone width', function (): void {
    /** @var SecuredBrowserTestCase $this */
    visit('/orders')->inDarkMode()->assertSee('401')->assertNoJavaScriptErrors()->screenshot(filename: 'error-401-dark');
    visit('/orders')->on()->mobile()->assertSee('401')->assertNoJavaScriptErrors()->screenshot(filename: 'error-401-mobile');
});
