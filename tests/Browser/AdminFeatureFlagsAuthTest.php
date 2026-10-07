<?php

declare(strict_types=1);

use Firefly\Tests\Browser\Support\SecuredBrowserTestCase;
use Firefly\Tests\Browser\Support\SignedInBrowserTestCase;

pest()->extend(SignedInBrowserTestCase::class);

it('requires an admin sign-in and attributes the write to the principal', function (): void {
    /** @var SignedInBrowserTestCase $this */
    $page = visit('/firefly/flags?flag=checkout-flow');

    $page->assertPathIs('/login')
        ->fill('username', SecuredBrowserTestCase::ADMIN)
        ->fill('password', SecuredBrowserTestCase::ADMIN_PASSWORD)
        ->click(SecuredBrowserTestCase::SIGN_IN_BUTTON)
        ->assertSee('checkout-flow')
        ->press('Save definition')
        ->assertSee('Saved [checkout-flow].')
        ->assertSeeIn('#flag-history', 'ada')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'flags-authenticated');
});

it('refuses a signed-in user without the admin role', function (): void {
    /** @var SignedInBrowserTestCase $this */
    $page = visit('/firefly/flags');

    $page->assertPathIs('/login')
        ->fill('username', SecuredBrowserTestCase::USER)
        ->fill('password', SecuredBrowserTestCase::USER_PASSWORD)
        ->click(SecuredBrowserTestCase::SIGN_IN_BUTTON)
        ->assertSee('403')
        ->assertDontSee('beta-banner')
        ->assertNoJavaScriptErrors();
});
