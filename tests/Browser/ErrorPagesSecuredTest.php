<?php

declare(strict_types=1);

use Firefly\Tests\Browser\Support\FixturePrincipalFilter;
use Firefly\Tests\Browser\Support\SecuredBrowserTestCase;

pest()->extend(SecuredBrowserTestCase::class);

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
    visit('/orders?'.FixturePrincipalFilter::QUERY.'=user')
        ->assertSee('403')
        ->assertSee('ACCESS_DENIED')
        ->assertSee('Access is denied.')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'error-403');
});

it('renders the 401 in dark mode and at phone width', function (): void {
    /** @var SecuredBrowserTestCase $this */
    visit('/orders')->inDarkMode()->assertSee('401')->assertNoJavaScriptErrors()->screenshot(filename: 'error-401-dark');
    visit('/orders')->on()->mobile()->assertSee('401')->assertNoJavaScriptErrors()->screenshot(filename: 'error-401-mobile');
});
