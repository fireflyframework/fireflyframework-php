<?php

declare(strict_types=1);

use Firefly\Security\Event\InteractiveAuthenticationSuccessEvent;
use Firefly\Security\OAuth2\Client\Tests\Support\OAuth2ClientCapstoneTestCase;
use Firefly\Security\Tests\Support\SecurityFlows;
use Firefly\Testing\Security\OAuth2\FakeAuthorizationServer;

/**
 * The whole login through the real pipeline: the start URL, the provider (real route), the callback, the
 * exchange (faked back channel), the id token against the JWKS, userinfo, the session-persisted principal,
 * the events, the redirect — and nothing secret on disk afterwards.
 */
uses(OAuth2ClientCapstoneTestCase::class, SecurityFlows::class);

it('signs the person in: PKCE exchange, id token verified, userinfo loaded, session regenerated, events published, tokens encrypted at rest', function () {
    /** @var OAuth2ClientCapstoneTestCase $this */
    $start = $this->startLogin();
    $provider = $this->follow($start, $start);
    $provider->assertRedirect();
    expect((string) $provider->headers->get('Location'))->toStartWith('http://localhost/login/oauth2/code/fake?code=');

    $callback = $this->follow($start, $provider);
    $callback->assertRedirect('/');

    // Fixation protection: the session the browser holds after the login is not the one it held before.
    expect($callback->getCookie($this->sessionCookieName())?->getValue())->not->toBe($start->getCookie($this->sessionCookieName())?->getValue());

    $this->forgetSession();
    $whoami = $this->followSession($callback)->getJson('/whoami');
    $whoami->assertOk()->assertJson(['name' => 'ada', 'authenticated' => true]);
    expect($whoami->json('authorities'))->toBe(['OIDC_USER', 'SCOPE_openid', 'SCOPE_profile', 'SCOPE_email']);
    $this->followSession($callback)->get('/home')->assertOk()->assertSee('Signed in as ada');

    // What the framework actually sent the provider.
    $token = $this->idp->lastTokenRequest();
    expect($token['grant_type'])->toBe('authorization_code')
        ->and($token['authorization'])->toBe('Basic '.base64_encode(FakeAuthorizationServer::CLIENT_ID.':'.FakeAuthorizationServer::CLIENT_SECRET))
        ->and($token['form']['redirect_uri'] ?? null)->toBe('http://localhost/login/oauth2/code/fake')
        ->and($token['form']['code_verifier'] ?? '')->toMatch('/^[A-Za-z0-9_-]{43}$/')
        ->and($token['form'])->not->toHaveKey('client_secret')
        ->and($this->idp->userInfoRequests)->toBe([$this->idp->issuedAccessTokens[0]])
        ->and($this->idp->jwksRequests)->toBe(1)
        ->and($this->idp->discoveryRequests)->toBe(1);

    // The event family, in Spring's order.
    expect($this->events->successes())->toHaveCount(1)
        ->and($this->events->interactive())->toHaveCount(1)
        ->and($this->events->interactive()[0]->mechanism)->toBe(InteractiveAuthenticationSuccessEvent::OAUTH2_LOGIN)
        ->and($this->events->interactive()[0]->authentication->getName())->toBe('ada')
        ->and($this->events->failures())->toBe([]);

    // Nothing secret reaches the session store in clear: not a token, not the secret, not the PKCE verifier.
    foreach ($this->sessionFiles() as $content) {
        expect($content)->not->toContain($this->idp->issuedIdTokens[0])
            ->not->toContain($this->idp->issuedAccessTokens[0])
            ->not->toContain(FakeAuthorizationServer::CLIENT_SECRET)
            ->not->toContain($token['form']['code_verifier'] ?? 'no-verifier');
    }
});

it('sends the person back to the page they were refused at', function () {
    /** @var OAuth2ClientCapstoneTestCase $this */
    $refused = $this->get('/home', ['Accept' => 'text/html']);
    $refused->assertRedirect('/login');

    $this->forgetSession();
    $start = $this->followSession($refused)->get('/oauth2/authorization/fake');
    $start->assertRedirect();
    $provider = $this->follow($refused, $start);
    $callback = $this->follow($refused, $provider);

    $callback->assertRedirect('http://localhost/home');
    $this->forgetSession();
    $this->followSession($callback)->get('/home')->assertOk()->assertSee('Signed in as ada');
});

it('is not the callback for an unknown registration or another method', function () {
    /** @var OAuth2ClientCapstoneTestCase $this */
    $notCallbacks = [['GET', '/login/oauth2/code/nope?code=x&state=y'], ['POST', '/login/oauth2/code/fake?code=x&state=y'], ['GET', '/login/oauth2/code/fake/extra?code=x&state=y']];

    // The filter lets each of these fall through, so what answers is the URL rules — `*` needs a principal
    // here, hence the entry point: a 401 for JSON, the login page for a browser — and never a refused login:
    // no failure event was published for them, and nothing reached the token endpoint.
    foreach ($notCallbacks as [$method, $url]) {
        $this->json($method, $url)->assertStatus(401)->assertJson(['code' => 'AUTHENTICATION_FAILED']);
    }
    $this->get('/login/oauth2/code/nope?code=x&state=y')->assertRedirect('/login');

    expect($this->events->failures())->toBe([])
        ->and($this->idp->tokenRequests)->toBe([]);
});
