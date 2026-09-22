<?php

declare(strict_types=1);

use Firefly\Security\OAuth2\Server\Tests\Support\OAuth2ServerCapstoneTestCase;
use Firefly\Security\OAuth2\Server\Web\Consent\SessionAuthenticationTime;
use Firefly\Security\Session\SavedRequest;
use Firefly\Testing\Security\OAuth2\OAuth2ServerTestClient;

uses(OAuth2ServerCapstoneTestCase::class);

/**
 * A sign-in some time ago, as the clock would leave it: the stamp the listener wrote at the login POST is moved
 * back and written through to the file the next request reads (the store merges the file OVER its memory, so a
 * put() alone would be overridden on the next start()).
 */
function signedInSecondsAgo(OAuth2ServerCapstoneTestCase $test, int $seconds): int
{
    $session = $test->sessionStore();
    $signedInAt = time() - $seconds;
    $session->put(SessionAuthenticationTime::KEY, $signedInAt);
    $session->save();

    return $signedInAt;
}

it('stamps the authentication instant at sign-in, not when the endpoint first looks, and the id token carries that instant', function () {
    /** @var OAuth2ServerCapstoneTestCase $this */
    $this->signIn();

    // The login POST stamped the session through InteractiveAuthenticationSuccessEvent — before any authorization request.
    expect($this->sessionStore()->get(SessionAuthenticationTime::KEY))->toBeInt();

    // Ten minutes later the code is issued: auth_time is the sign-in instant, never the code-issue instant.
    $signedInAt = signedInSecondsAgo($this, 600);
    $tokens = $this->oauth2()->tokens('public-spa', OAuth2ServerCapstoneTestCase::SPA_REDIRECT_URI, 'openid');
    /** @var string $idToken */
    $idToken = $tokens['id_token'];

    expect($this->decodeJwt($idToken)['auth_time'])->toBe($signedInAt)
        ->and($this->sessionStore()->get(SessionAuthenticationTime::KEY))->toBe($signedInAt);
});

it('sends a session older than max_age back to sign in, and the fresh sign-in returns to the request once and issues the code', function () {
    /** @var OAuth2ServerCapstoneTestCase $this */
    $this->signIn();
    signedInSecondsAgo($this, 600);
    $session = $this->sessionStore();
    $oauth2 = $this->oauth2();

    // 1. Exceeded: the stored principal and the instant are cleared, the request is saved with max_age still on it.
    $response = $oauth2->authorize('public-spa', OAuth2ServerCapstoneTestCase::SPA_REDIRECT_URI, 'openid', ['max_age' => '60']);
    $response->assertRedirect('http://localhost/login');
    $saved = $session->get(SavedRequest::KEY);
    expect($saved)->toBeString()->toStartWith('http://localhost/oauth2/authorize?')->toContain('max_age=60')
        ->and($session->get(SessionAuthenticationTime::KEY))->toBeNull();

    // 2. Signing in again stamps the fresh instant and sends the browser straight back to the saved request.
    $before = time();
    $page = $this->get('/login');
    $signedIn = $this->post('/login', ['username' => 'ada', 'password' => 'secret', '_token' => $this->csrfTokenFrom($page)]);
    $signedIn->assertStatus(302);
    $back = (string) $signedIn->headers->get('Location');
    expect($back)->toStartWith('http://localhost/oauth2/authorize?')->toContain('max_age=60');
    $this->followSession($signedIn);
    $stamped = $session->get(SessionAuthenticationTime::KEY);
    expect($stamped)->toBeInt()->toBeGreaterThanOrEqual($before);

    // 3. The return visit is within max_age now: the code, not the login page again.
    $redirect = $this->get($back);
    $redirect->assertStatus(302);
    expect((string) $redirect->headers->get('Location'))->toStartWith(OAuth2ServerCapstoneTestCase::SPA_REDIRECT_URI.'?code=');

    $tokens = $oauth2->exchangeCode('public-spa', OAuth2ServerTestClient::codeFrom($redirect), OAuth2ServerCapstoneTestCase::SPA_REDIRECT_URI);
    $tokens->assertOk();
    /** @var string $idToken */
    $idToken = $tokens->json('id_token');
    expect($this->decodeJwt($idToken)['auth_time'])->toBe($stamped);
});

it('answers login_required for prompt=none when max_age is exceeded, and leaves the session signed in', function () {
    /** @var OAuth2ServerCapstoneTestCase $this */
    $this->signIn();
    $signedInAt = signedInSecondsAgo($this, 600);
    $oauth2 = $this->oauth2();

    $response = $oauth2->authorize('public-spa', OAuth2ServerCapstoneTestCase::SPA_REDIRECT_URI, 'openid', ['max_age' => '60', 'prompt' => 'none']);
    $response->assertStatus(302);
    expect((string) $response->headers->get('Location'))->toStartWith(OAuth2ServerCapstoneTestCase::SPA_REDIRECT_URI.'?')
        ->toContain('error=login_required')
        ->toContain('state='.$oauth2->state());

    // Nothing was cleared: the same session is still signed in, still stamped, and passes a max_age it is within.
    expect($this->sessionStore()->get(SessionAuthenticationTime::KEY))->toBe($signedInAt);
    $within = $oauth2->authorize('public-spa', OAuth2ServerCapstoneTestCase::SPA_REDIRECT_URI, 'openid', ['max_age' => '3600']);
    $within->assertStatus(302);
    expect((string) $within->headers->get('Location'))->toStartWith(OAuth2ServerCapstoneTestCase::SPA_REDIRECT_URI.'?code=');
});
