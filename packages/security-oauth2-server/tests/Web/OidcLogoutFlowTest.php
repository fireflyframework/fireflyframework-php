<?php

declare(strict_types=1);

use Firefly\Security\OAuth2\Server\Jose\JwtGenerator;
use Firefly\Security\OAuth2\Server\Tests\Support\OAuth2ServerCapstoneTestCase;

/**
 * A capstone with `firefly.security.logout.delete_cookies` set, so the LAST case can hold RP-initiated logout to
 * the same `firefly.security.logout.*` keys the form logout obeys: both paths run firefly/security's shared
 * LogoutHandler, and a key that reached only one of them would be the defect this class exists to catch.
 */
abstract class OidcLogoutCapstoneTestCase extends OAuth2ServerCapstoneTestCase
{
    protected function serverOverrides(): array
    {
        return ['firefly.security.logout.delete_cookies' => ['theme']];
    }
}

uses(OidcLogoutCapstoneTestCase::class);

it('ends the session for a valid id_token_hint, publishes the logout, and redirects to the registered URI with the state', function () {
    /** @var OidcLogoutCapstoneTestCase $this */
    $this->signIn();
    $oauth2 = $this->oauth2();
    $tokens = $oauth2->tokens('web-app', OAuth2ServerCapstoneTestCase::REDIRECT_URI, 'openid', OAuth2ServerCapstoneTestCase::WEB_APP_SECRET);
    /** @var string $idToken */
    $idToken = $tokens['id_token'];
    $this->events->reset();

    $response = $this->get('/connect/logout?'.http_build_query(['id_token_hint' => $idToken, 'post_logout_redirect_uri' => OAuth2ServerCapstoneTestCase::POST_LOGOUT_URI, 'state' => 'bye-1', 'client_id' => 'web-app']));
    $response->assertRedirect(OAuth2ServerCapstoneTestCase::POST_LOGOUT_URI.'?state=bye-1');

    expect($this->events->logouts())->toHaveCount(1)
        ->and($this->events->logouts()[0]->authentication?->getName())->toBe('ada');

    $this->forgetSession();
    $this->getJson('/api/profile')->assertStatus(401);
});

it('redirects to / without a post_logout_redirect_uri, accepts an expired hint, and works for a browser that is already signed out', function () {
    /** @var OidcLogoutCapstoneTestCase $this */
    $this->signIn();
    $tokens = $this->oauth2()->tokens('web-app', OAuth2ServerCapstoneTestCase::REDIRECT_URI, 'openid', OAuth2ServerCapstoneTestCase::WEB_APP_SECRET);
    /** @var string $idToken */
    $idToken = $tokens['id_token'];

    $this->get('/connect/logout?id_token_hint='.$idToken)->assertRedirect('http://localhost/');

    /** @var JwtGenerator $jwt */
    $jwt = $this->app()->make(JwtGenerator::class);
    $expired = $jwt->encode(['iss' => 'http://localhost', 'sub' => 'ada', 'aud' => ['web-app'], 'iat' => time() - 7200, 'exp' => time() - 3600]);
    $this->forgetSession();
    $this->forgetCookies();
    $this->post('/connect/logout', ['id_token_hint' => $expired, 'post_logout_redirect_uri' => OAuth2ServerCapstoneTestCase::POST_LOGOUT_URI])
        ->assertRedirect(OAuth2ServerCapstoneTestCase::POST_LOGOUT_URI);
});

it('refuses a missing or foreign hint, an unregistered redirect URI, a mismatched client_id, and a hint for another user', function () {
    /** @var OidcLogoutCapstoneTestCase $this */
    $this->signIn();
    $tokens = $this->oauth2()->tokens('web-app', OAuth2ServerCapstoneTestCase::REDIRECT_URI, 'openid', OAuth2ServerCapstoneTestCase::WEB_APP_SECRET);
    /** @var string $hint */
    $hint = $tokens['id_token'];
    /** @var JwtGenerator $jwt */
    $jwt = $this->app()->make(JwtGenerator::class);

    $this->get('/connect/logout')->assertStatus(400)->assertJson(['error' => 'invalid_request']);
    $this->get('/connect/logout?id_token_hint=garbage')->assertStatus(400)->assertJson(['error' => 'invalid_token']);
    $this->get('/connect/logout?id_token_hint='.$hint.'&post_logout_redirect_uri=https://evil.test/')->assertStatus(400)->assertJson(['error' => 'invalid_request']);
    $this->get('/connect/logout?id_token_hint='.$hint.'&client_id=svc')->assertStatus(400)->assertJson(['error' => 'invalid_request']);

    $stranger = $jwt->encode(['iss' => 'https://other.test', 'sub' => 'ada', 'aud' => ['web-app'], 'iat' => time(), 'exp' => time() + 60]);
    $this->get('/connect/logout?id_token_hint='.$stranger)->assertStatus(400)->assertJson(['error' => 'invalid_token']);

    // ada is signed in; a hint issued to root must not end ada's session.
    $rootHint = $jwt->encode(['iss' => 'http://localhost', 'sub' => 'root', 'aud' => ['web-app'], 'iat' => time(), 'exp' => time() + 60]);
    $this->get('/connect/logout?id_token_hint='.$rootHint)->assertStatus(400)->assertJson(['error' => 'invalid_token']);
    $this->getJson('/api/profile')->assertOk()->assertJson(['sub' => 'ada']);
});

it('expires every delete_cookies name on /connect/logout exactly as the form logout does — one LogoutHandler behind both', function () {
    /** @var OidcLogoutCapstoneTestCase $this */
    $this->signIn();
    $tokens = $this->oauth2()->tokens('web-app', OAuth2ServerCapstoneTestCase::REDIRECT_URI, 'openid', OAuth2ServerCapstoneTestCase::WEB_APP_SECRET);
    /** @var string $idToken */
    $idToken = $tokens['id_token'];

    $rp = $this->withCookie('theme', 'dark')->get('/connect/logout?id_token_hint='.$idToken);
    $rp->assertRedirect('http://localhost/');
    $viaRp = $rp->getCookie('theme');
    expect($viaRp)->not->toBeNull()
        ->and($viaRp?->getExpiresTime())->toBeLessThan(time());

    // The framework's own POST /logout expires the same cookie, from the same bean.
    $this->signIn();
    $page = $this->get('/login');
    $form = $this->withCookie('theme', 'dark')->post('/logout', ['_token' => $this->csrfTokenFrom($page)]);
    $viaForm = $form->getCookie('theme');
    expect($viaForm)->not->toBeNull()
        ->and($viaForm?->getExpiresTime())->toBeLessThan(time());
});
