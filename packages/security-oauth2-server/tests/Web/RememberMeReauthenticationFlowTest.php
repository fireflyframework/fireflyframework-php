<?php

declare(strict_types=1);

use Firefly\Security\Event\InteractiveAuthenticationSuccessEvent;
use Firefly\Security\OAuth2\Server\Tests\Support\OAuth2ServerCapstoneTestCase;
use Firefly\Security\OAuth2\Server\Web\Consent\SessionAuthenticationTime;
use Firefly\Security\Session\SavedRequest;
use Firefly\Testing\Security\OAuth2\OAuth2ServerTestClient;

/**
 * The authorization endpoint beside the remember-me cookie: a demand for a fresh sign-in (`prompt=login`, an exceeded
 * `max_age`) must be met by a credential, never by the cookie signing the browser back in on the return trip, and a
 * session the cookie alone opened is signed in but not ACTIVELY authenticated (OpenID Connect Core §3.1.2.1).
 */
abstract class OAuth2RememberMeCapstoneTestCase extends OAuth2ServerCapstoneTestCase
{
    protected function serverOverrides(): array
    {
        return [
            'firefly.security.remember_me.enabled' => true,
            'firefly.security.remember_me.key' => str_repeat('remember-', 5),
        ];
    }

    /** Sign in with the box ticked and keep the session; the remember-me cookie's value, for a request that sends it alone. */
    public function signInRemembered(): string
    {
        $page = $this->get('/login');
        $signedIn = $this->followSession($page)->post('/login', ['username' => 'ada', 'password' => 'secret', '_token' => $this->csrfTokenFrom($page), 'remember-me' => '1']);
        $signedIn->assertStatus(302);
        $this->followSession($signedIn);

        $token = (string) $signedIn->getCookie('remember-me')?->getValue();
        expect($token)->not->toBe('');

        return $token;
    }
}

uses(OAuth2RememberMeCapstoneTestCase::class);

it('expires the remember-me cookie when it sends the browser to sign in again, so the return trip cannot be the cookie signing it back in', function () {
    /** @var OAuth2RememberMeCapstoneTestCase $this */
    $token = $this->signInRemembered();
    $oauth2 = $this->oauth2();
    $session = $this->sessionStore();

    // 1. prompt=login with the cookie riding along: the login page, the stored principal and the instant cleared, the
    //    request saved without the prompt — and the cookie expired on the SAME response, exactly as logout expires it.
    $response = $this->withCookie('remember-me', $token)->oauth2()->authorize('public-spa', OAuth2ServerCapstoneTestCase::SPA_REDIRECT_URI, 'openid', ['prompt' => 'login']);
    $response->assertRedirect('http://localhost/login');
    expect($session->get(SessionAuthenticationTime::KEY))->toBeNull()
        ->and($session->get(SavedRequest::KEY))->toBeString()->not->toContain('prompt=login')
        ->and($response->getCookie('remember-me')?->getExpiresTime())->toBeInt()->toBeLessThan(time());

    // 2. The browser dropped the cookie: the login page is anonymous, and max_age=60 still wants a sign-in.
    $this->forgetCookies()->followSession($response);
    $this->events->reset();
    $this->get('/login')->assertOk();
    expect($this->events->interactive())->toBe([]);
    $oauth2->authorize('public-spa', OAuth2ServerCapstoneTestCase::SPA_REDIRECT_URI, 'openid', ['max_age' => '60'])->assertRedirect('http://localhost/login');

    // 3. The credential, once: the fresh sign-in is stamped, the return visit is within max_age and issues the code.
    $before = time();
    $page = $this->get('/login');
    $signedIn = $this->post('/login', ['username' => 'ada', 'password' => 'secret', '_token' => $this->csrfTokenFrom($page)]);
    $signedIn->assertStatus(302);
    $back = (string) $signedIn->headers->get('Location');
    expect($back)->toStartWith('http://localhost/oauth2/authorize?')->toContain('max_age=60');
    $this->followSession($signedIn);

    $redirect = $this->get($back);
    $redirect->assertStatus(302);
    expect((string) $redirect->headers->get('Location'))->toStartWith(OAuth2ServerCapstoneTestCase::SPA_REDIRECT_URI.'?code=');
    $tokens = $oauth2->exchangeCode('public-spa', OAuth2ServerTestClient::codeFrom($redirect), OAuth2ServerCapstoneTestCase::SPA_REDIRECT_URI);
    $tokens->assertOk();
    /** @var string $idToken */
    $idToken = $tokens->json('id_token');
    expect($this->decodeJwt($idToken)['auth_time'])->toBeInt()->toBeGreaterThanOrEqual($before);
});

it('never lets the cookie alone satisfy prompt=login or max_age: a session it opened is signed in, not actively authenticated', function () {
    /** @var OAuth2RememberMeCapstoneTestCase $this */
    $token = $this->signInRemembered();
    $oauth2 = $this->oauth2();

    // 1. prompt=login, then a browser that STILL sends the cookie to the login page (one that ignored the expiry): the
    //    remember-me filter signs it back in through the REMEMBER_ME mechanism — and that is not a fresh sign-in.
    $response = $this->withCookie('remember-me', $token)->oauth2()->authorize('public-spa', OAuth2ServerCapstoneTestCase::SPA_REDIRECT_URI, 'openid', ['prompt' => 'login']);
    $response->assertRedirect('http://localhost/login');
    $this->followSession($response);
    $this->events->reset();
    $page = $this->withCookie('remember-me', $token)->get('/login');
    $page->assertOk();
    expect($this->events->interactive())->toHaveCount(1)
        ->and($this->events->interactive()[0]->mechanism)->toBe(InteractiveAuthenticationSuccessEvent::REMEMBER_ME)
        ->and($this->sessionStore()->get(SessionAuthenticationTime::KEY))->not->toBeInt();

    // 2. Signed in by the cookie, the browser is still sent to sign in for max_age=60 (and the cookie is expired again),
    //    while a request without max_age proceeds — with an id token that carries NO auth_time, since none is known.
    $this->followSession($page);
    $again = $oauth2->authorize('public-spa', OAuth2ServerCapstoneTestCase::SPA_REDIRECT_URI, 'openid', ['max_age' => '60']);
    $again->assertRedirect('http://localhost/login');
    expect($again->getCookie('remember-me')?->getExpiresTime())->toBeInt()->toBeLessThan(time());

    // 3. A session the cookie ALONE opened — the server-side session gone, as an expiry or a restart leaves it.
    $this->forgetSession();
    $this->forgetCookies()->withCredentials()->withCookie('remember-me', $token);
    $this->events->reset();
    $plain = $oauth2->authorize('public-spa', OAuth2ServerCapstoneTestCase::SPA_REDIRECT_URI, 'openid');
    $plain->assertStatus(302);
    expect((string) $plain->headers->get('Location'))->toStartWith(OAuth2ServerCapstoneTestCase::SPA_REDIRECT_URI.'?code=')
        ->and($this->events->interactive())->toHaveCount(1)
        ->and($this->events->interactive()[0]->mechanism)->toBe(InteractiveAuthenticationSuccessEvent::REMEMBER_ME);
    $this->followSession($plain);
    $tokens = $oauth2->exchangeCode('public-spa', OAuth2ServerTestClient::codeFrom($plain), OAuth2ServerCapstoneTestCase::SPA_REDIRECT_URI);
    $tokens->assertOk();
    /** @var string $idToken */
    $idToken = $tokens->json('id_token');
    expect($this->decodeJwt($idToken))->not->toHaveKey('auth_time');

    // 4. ...and that same remembered session is sent to sign in for max_age, however large: it was never actively authenticated.
    $this->forgetCookies()->followSession($plain);
    $oauth2->authorize('public-spa', OAuth2ServerCapstoneTestCase::SPA_REDIRECT_URI, 'openid', ['max_age' => '86400'])->assertRedirect('http://localhost/login');
});
