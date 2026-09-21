<?php

declare(strict_types=1);

use Firefly\Testing\Security\OAuth2\FakeAuthorizationServer;
use Firefly\Tests\Browser\Support\OAuth2LoginBrowserTestCase;

pest()->extend(OAuth2LoginBrowserTestCase::class);

it('signs in through the provider from the login page — every hop, the consent page, the page the person was refused at — and signs out at the provider too', function (): void {
    /** @var OAuth2LoginBrowserTestCase $this */
    $this->idp->requireConsent();
    $origin = OAuth2LoginBrowserTestCase::origin();

    // 1. The entry point sent the browser to the framework's login page: the provider is listed, there is no password form.
    $page = visit('/browser-fixture/account');
    $page->assertPathIs('/login')
        ->assertSee('Sign in with Fake IdP')
        ->assertDontSee('Username')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'oauth2-login-page');

    // 2. The start URL redirected to the provider with state, nonce and a PKCE challenge; the fake stopped at its consent page.
    $page->click('Sign in with Fake IdP')
        ->assertPathIs('/fake-idp/authorize')
        ->assertQueryStringHas('response_type', 'code')
        ->assertQueryStringHas('client_id', FakeAuthorizationServer::CLIENT_ID)
        ->assertQueryStringHas('redirect_uri', $origin.'/login/oauth2/code/fake')
        ->assertQueryStringHas('scope', 'openid profile email')
        ->assertQueryStringHas('state')
        ->assertQueryStringHas('nonce')
        ->assertQueryStringHas('code_challenge')
        ->assertQueryStringHas('code_challenge_method', 'S256')
        ->assertSee('asks to sign you in as ada')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'oauth2-consent');

    // 3. Approving sent the code back; the callback exchanged it and landed on the page the person was refused at.
    $page->press('Allow')
        ->assertPathIs('/browser-fixture/account')
        ->assertQueryStringMissing('code')
        ->assertQueryStringMissing('state')
        ->assertSee('Signed in as ada')
        ->assertSee('ada@example.com')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'oauth2-signed-in');

    $token = $this->idp->lastTokenRequest();
    expect($this->idp->lastAuthorizationRequest()['redirect_uri'] ?? null)->toBe($origin.'/login/oauth2/code/fake')
        ->and($token['grant_type'])->toBe('authorization_code')
        ->and($token['authorization'])->toStartWith('Basic ')
        ->and($token['form']['redirect_uri'] ?? null)->toBe($origin.'/login/oauth2/code/fake')
        ->and($token['form']['code_verifier'] ?? '')->toMatch('/^[A-Za-z0-9_-]{43}$/')
        ->and($this->idp->userInfoRequests)->toBe([$this->idp->issuedAccessTokens[0]])
        ->and($this->idp->jwksRequests)->toBe(1)
        ->and($this->idp->discoveryRequests)->toBe(1);

    // 4. The same browser context is signed in everywhere now: the skeleton's own page renders.
    $page->navigate('/')
        ->assertPathIs('/')
        ->assertSee('Hello, LaraFly')
        ->assertNoJavaScriptErrors();

    // 5. Nothing secret in the session store.
    foreach ($this->sessionFiles() as $content) {
        expect($content)->not->toContain($this->idp->issuedIdTokens[0])
            ->not->toContain($this->idp->issuedAccessTokens[0])
            ->not->toContain(FakeAuthorizationServer::CLIENT_SECRET);
    }

    // 6. Sign out: the logout POST went to the provider's end-session endpoint with the id token, which sent the
    //    browser back to the post-logout URI — the framework login page, signed-out notice showing.
    $page->navigate('/browser-fixture/account')
        ->assertSee('Signed in as ada')
        ->press('Sign out')
        ->assertPathIs('/login')
        ->assertQueryStringHas('logout')
        ->assertSee('You have signed out.')
        ->assertSee('Sign in with Fake IdP')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'oauth2-signed-out');

    expect($this->idp->endSessionRequests)->toHaveCount(1)
        ->and($this->idp->endSessionRequests[0]['id_token_hint'] ?? null)->toBe($this->idp->issuedIdTokens[0])
        ->and($this->idp->endSessionRequests[0]['client_id'] ?? null)->toBe(FakeAuthorizationServer::CLIENT_ID)
        ->and($this->idp->endSessionRequests[0]['post_logout_redirect_uri'] ?? null)->toBe($origin.'/login?logout');

    // 7. The session is gone: the protected page is refused again.
    $page->navigate('/browser-fixture/account')->assertPathIs('/login')->assertDontSee('Signed in as');
});

it('lands straight on the default success URL when the provider approves without a consent page', function (): void {
    /** @var OAuth2LoginBrowserTestCase $this */
    visit('/oauth2/authorization/fake')
        ->assertPathIs('/')
        ->assertSee('Hello, LaraFly')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'oauth2-auto-approved');

    expect($this->idp->tokenRequests)->toHaveCount(1)
        ->and($this->idp->issuedIdTokens)->toHaveCount(1);
});

it('shows the provider sentence on the login page when the provider refuses, without ever calling the token endpoint', function (): void {
    /** @var OAuth2LoginBrowserTestCase $this */
    $this->idp->refuseAuthorization('access_denied');

    visit('/oauth2/authorization/fake')
        ->assertPathIs('/login')
        ->assertQueryStringHas('error')
        ->assertSee('Signing in with the provider did not work')
        ->assertDontSee('Check the username and the password')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'oauth2-refused');

    expect($this->idp->tokenRequests)->toBe([]);
});

it('refuses an id token whose nonce is not the one it sent, on the same page', function (): void {
    /** @var OAuth2LoginBrowserTestCase $this */
    $this->idp->overrideIdTokenClaims(['nonce' => 'not-the-one-sent']);

    $page = visit('/oauth2/authorization/fake');
    $page->assertPathIs('/login')
        ->assertQueryStringHas('error')
        ->assertSee('Signing in with the provider did not work');

    expect($this->idp->tokenRequests)->toHaveCount(1)
        ->and($this->idp->userInfoRequests)->toBe([]);

    $page->navigate('/browser-fixture/account')->assertPathIs('/login');
});

it('renders the providers-only login page in dark mode and at phone width', function (): void {
    /** @var OAuth2LoginBrowserTestCase $this */
    visit('/login')->inDarkMode()->assertSee('Sign in with Fake IdP')->assertNoJavaScriptErrors()->screenshot(filename: 'oauth2-login-page-dark');
    visit('/login')->on()->mobile()->assertSee('Sign in with Fake IdP')->assertNoJavaScriptErrors()->screenshot(filename: 'oauth2-login-page-mobile');
});
