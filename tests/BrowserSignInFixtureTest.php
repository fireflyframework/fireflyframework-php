<?php

declare(strict_types=1);

use Firefly\Tests\Browser\Support\SecuredBrowserTestCase;
use Firefly\Tests\Browser\Support\SignedInBrowserTestCase;

pest()->extend(SignedInBrowserTestCase::class);

/**
 * The browser suite is excluded from the default gate, so its sign-in fixture would otherwise be proved only
 * by CI's browser job. These two cases drive the SAME fixture through Laravel's test client — no Chromium, no
 * Node: this file is outside tests/Browser and never calls visit(), which is the plugin's own definition of
 * "not a browser test" — and pin the two things every browser flow stands on: the real form signs ada in and
 * honours the saved request, and the session Store is forgotten between requests so a request that carries
 * no cookie is anonymous. Like BrowserFixtureProvidersTest, it keeps the fixture honest from inside
 * `composer test`.
 */
it('signs ada in through the real form and comes back to the saved request, with no browser involved', function (): void {
    /** @var SignedInBrowserTestCase $this */
    $refused = $this->get('/orders');
    $refused->assertRedirect('/login');

    $page = $this->followSession($refused)->get('/login');
    $page->assertOk()->assertSee('Sign in');

    $login = $this->followSession($page)->post('/login', [
        'username' => SecuredBrowserTestCase::ADMIN,
        'password' => SecuredBrowserTestCase::ADMIN_PASSWORD,
        '_token' => $this->csrfTokenFrom($page),
    ]);
    $login->assertRedirect('/orders');

    $this->followSession($login)->get('/orders')->assertOk()->assertJsonStructure(['items']);
});

it('forgets the session store between requests, so a request without the cookie is anonymous', function (): void {
    /** @var SignedInBrowserTestCase $this */
    $page = $this->get('/login');
    $login = $this->followSession($page)->post('/login', [
        'username' => SecuredBrowserTestCase::ADMIN,
        'password' => SecuredBrowserTestCase::ADMIN_PASSWORD,
        '_token' => $this->csrfTokenFrom($page),
    ]);
    $login->assertRedirect('/');

    // Same process, no cookie. Without the fixture's terminating forget, Laravel's Store singleton would still
    // hold ada's context in memory (Store::loadSession() array_replace()s the handler's data OVER the
    // attributes it kept) and this request would be answered signed in.
    $this->forgetCookies();
    $this->get('/orders')->assertRedirect('/login');
});
