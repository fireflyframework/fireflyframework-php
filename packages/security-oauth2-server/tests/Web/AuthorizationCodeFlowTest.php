<?php

declare(strict_types=1);

use Firefly\Security\OAuth2\Server\Authorization\OAuth2AuthorizationConsentService;
use Firefly\Security\OAuth2\Server\Authorization\OAuth2AuthorizationService;
use Firefly\Security\OAuth2\Server\Authorization\OAuth2TokenType;
use Firefly\Security\OAuth2\Server\Tests\Support\OAuth2ServerCapstoneTestCase;
use Firefly\Security\OAuth2\Server\Web\Consent\SessionAuthenticationTime;
use Firefly\Security\Session\SavedRequest;
use Firefly\Testing\Security\OAuth2\OAuth2ServerTestClient;

uses(OAuth2ServerCapstoneTestCase::class);

it('runs the whole authorization-code flow through the real pipeline: login page, consent, code, tokens, a protected API call', function () {
    /** @var OAuth2ServerCapstoneTestCase $this */
    $oauth2 = $this->oauth2();

    // 1. An anonymous browser is sent to the framework's login page with the request saved.
    $first = $oauth2->authorize('web-app', OAuth2ServerCapstoneTestCase::REDIRECT_URI, 'openid profile orders:read', ['nonce' => 'nonce-1']);
    $first->assertRedirect('http://localhost/login');
    $this->followSession($first);
    expect($this->sessionStore()->get(SavedRequest::KEY))->toStartWith('http://localhost/oauth2/authorize?');

    // 2. Signing in sends the browser straight back to the authorization request.
    $page = $this->get('/login');
    $signedIn = $this->post('/login', ['username' => 'ada', 'password' => 'secret', '_token' => $this->csrfTokenFrom($page)]);
    $signedIn->assertStatus(302);
    $back = (string) $signedIn->headers->get('Location');
    expect($back)->toStartWith('http://localhost/oauth2/authorize?')->toContain('nonce=nonce-1');
    $this->followSession($signedIn);

    // 3. The consent page: the client, the three scopes, the session token and the pending state.
    $consent = $this->get($back);
    $consent->assertOk()
        ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
        ->assertSee('The web application')
        ->assertSee('name="scope[]" value="openid" checked', escape: false)
        ->assertSee('name="scope[]" value="orders:read" checked', escape: false)
        ->assertSee('name="_token"', escape: false)
        ->assertSee('name="state"', escape: false);

    // 4. Approval issues the code and redirects to the registered URI with the client's state echoed.
    $redirect = $oauth2->approveConsent($consent);
    $redirect->assertStatus(302);
    $location = (string) $redirect->headers->get('Location');
    expect($location)->toStartWith(OAuth2ServerCapstoneTestCase::REDIRECT_URI.'?')->toContain('state='.$oauth2->state());
    $code = OAuth2ServerTestClient::codeFrom($redirect);

    // 5. The exchange: a confidential client with Basic credentials and the verifier.
    $tokens = $oauth2->exchangeCode('web-app', $code, OAuth2ServerCapstoneTestCase::REDIRECT_URI, OAuth2ServerCapstoneTestCase::WEB_APP_SECRET);
    $tokens->assertOk()->assertJson(['token_type' => 'Bearer', 'expires_in' => 300, 'scope' => 'openid profile orders:read']);
    /** @var array<string,mixed> $body */
    $body = $tokens->json();
    expect($body)->toHaveKeys(['access_token', 'refresh_token', 'id_token']);
    /** @var string $accessToken */
    $accessToken = $body['access_token'];
    /** @var string $idToken */
    $idToken = $body['id_token'];
    /** @var string $refreshToken */
    $refreshToken = $body['refresh_token'];

    $access = $this->decodeJwt($accessToken);
    $id = $this->decodeJwt($idToken);
    $sessionId = $this->sessionStore()->getId();
    expect($access)->toMatchArray(['iss' => 'http://localhost', 'sub' => 'ada', 'aud' => ['web-app'], 'scope' => 'openid profile orders:read', 'client_id' => 'web-app'])
        ->and($id)->toMatchArray(['iss' => 'http://localhost', 'sub' => 'ada', 'aud' => ['web-app'], 'azp' => 'web-app', 'nonce' => 'nonce-1'])
        ->and($id['auth_time'])->toBeInt()
        ->and($id['sid'])->toBe($sessionId)
        ->and($id['at_hash'])->toBe(rtrim(strtr(base64_encode(substr(hash('sha256', $accessToken, true), 0, 16)), '+/', '-_'), '='));

    // 6. The token is accepted by the resource-server filter of the same application. It is sent as an API client
    //    sends it — the bearer alone, from a fresh process: a session cookie beside it would name the browser's own
    //    principal first (the persistence filter at -94 runs ahead of the resource-server filter at -85, which
    //    then no-ops), and the in-memory session store would do the same without the cookie.
    $this->forgetSession();
    $this->forgetCookies()->withHeader('Authorization', 'Bearer '.$accessToken)->getJson('/api/profile')
        ->assertOk()
        ->assertJson(['sub' => 'ada', 'authorities' => ['SCOPE_openid', 'SCOPE_profile', 'SCOPE_orders:read'], 'authenticated' => true]);
    $this->flushHeaders();
    $this->followSession($redirect);

    // 7. The consent is remembered: the next request for the same scopes skips the page.
    expect($this->app()->make(OAuth2AuthorizationConsentService::class)->findById('web-app', 'ada')?->scopes)->toBe(['openid', 'profile', 'orders:read']);
    $again = $oauth2->authorize('web-app', OAuth2ServerCapstoneTestCase::REDIRECT_URI, 'openid profile');
    $again->assertStatus(302);
    expect((string) $again->headers->get('Location'))->toStartWith(OAuth2ServerCapstoneTestCase::REDIRECT_URI.'?code=');

    // 8. A NEW scope asks again; prompt=consent asks even for the same scopes.
    $oauth2->authorize('web-app', OAuth2ServerCapstoneTestCase::REDIRECT_URI, 'openid email')->assertOk()->assertSee('name="scope[]" value="email"', escape: false);
    $oauth2->authorize('web-app', OAuth2ServerCapstoneTestCase::REDIRECT_URI, 'openid', ['prompt' => 'consent'])->assertOk();

    // 9. The code was single-use: the authorization holds it invalidated, and the tokens are stored by hash only.
    /** @var OAuth2AuthorizationService $service */
    $service = $this->app()->make(OAuth2AuthorizationService::class);
    $stored = $service->findByToken($refreshToken, OAuth2TokenType::RefreshToken);
    expect($stored?->token(OAuth2TokenType::AuthorizationCode)?->isInvalidated())->toBeTrue()
        ->and($stored?->attribute('nonce'))->toBe('nonce-1')
        ->and($stored?->attribute('sid'))->toBe($sessionId)
        ->and($stored?->token(OAuth2TokenType::AccessToken)?->value)->toBeNull();
});

it('issues a code to a public client without consent, exchanges it with PKCE alone, and issues no refresh token', function () {
    /** @var OAuth2ServerCapstoneTestCase $this */
    $this->actingAsPrincipal('ada', ['ROLE_USER']);
    $oauth2 = $this->oauth2();

    $redirect = $oauth2->authorize('public-spa', OAuth2ServerCapstoneTestCase::SPA_REDIRECT_URI, 'openid profile');
    $redirect->assertStatus(302);
    expect((string) $redirect->headers->get('Location'))->toStartWith(OAuth2ServerCapstoneTestCase::SPA_REDIRECT_URI.'?code=');

    $tokens = $oauth2->exchangeCode('public-spa', OAuth2ServerTestClient::codeFrom($redirect), OAuth2ServerCapstoneTestCase::SPA_REDIRECT_URI);
    $tokens->assertOk()->assertJson(['scope' => 'openid profile']);
    expect($tokens->json())->toHaveKeys(['access_token', 'id_token'])->not->toHaveKey('refresh_token');
});

it('asks again for prompt=login: the stored principal and the authentication instant are cleared, and the saved request loses the prompt', function () {
    /** @var OAuth2ServerCapstoneTestCase $this */
    $this->signIn();
    $session = $this->sessionStore();

    // A first, plain authorization stamps the session's authentication instant.
    $this->oauth2()->authorize('public-spa', OAuth2ServerCapstoneTestCase::SPA_REDIRECT_URI, 'openid')->assertStatus(302);
    expect($session->get(SessionAuthenticationTime::KEY))->toBeInt();

    $response = $this->oauth2()->authorize('public-spa', OAuth2ServerCapstoneTestCase::SPA_REDIRECT_URI, 'openid', ['prompt' => 'login']);
    $response->assertRedirect('http://localhost/login');

    $saved = $session->get(SavedRequest::KEY);
    expect($saved)->toBeString()->toStartWith('http://localhost/oauth2/authorize?')
        ->and($saved)->not->toContain('prompt=login')
        ->and($session->get(SessionAuthenticationTime::KEY))->toBeNull();

    // The stored principal was cleared: the next plain request is anonymous again.
    $this->forgetSession();
    $this->getJson('/api/profile')->assertStatus(401);
});

it('denies when the user refuses, redirecting with access_denied and the state', function () {
    /** @var OAuth2ServerCapstoneTestCase $this */
    $this->signIn();
    $oauth2 = $this->oauth2();
    $page = $oauth2->authorize('web-app', OAuth2ServerCapstoneTestCase::REDIRECT_URI, 'openid');
    $page->assertOk();

    $html = (string) $page->getContent();
    preg_match('/name="_token" value="([^"]+)"/', $html, $token);
    preg_match('/name="state" value="([^"]+)"/', $html, $state);
    $denied = $this->followSession($page)->post('/oauth2/authorize', ['_token' => $token[1] ?? '', 'state' => $state[1] ?? '', 'action' => 'deny']);

    $denied->assertStatus(302);
    expect((string) $denied->headers->get('Location'))->toBe(OAuth2ServerCapstoneTestCase::REDIRECT_URI.'?error=access_denied&error_description=The+resource+owner+denied+the+request.&state='.$oauth2->state());
});

it('refuses a consent POST without the session token (403) and one whose state names no pending request (400)', function () {
    /** @var OAuth2ServerCapstoneTestCase $this */
    $this->signIn();
    $page = $this->oauth2()->authorize('web-app', OAuth2ServerCapstoneTestCase::REDIRECT_URI, 'openid');
    preg_match('/name="state" value="([^"]+)"/', (string) $page->getContent(), $state);

    $this->followSession($page)->post('/oauth2/authorize', ['state' => $state[1] ?? '', 'action' => 'approve'])->assertStatus(403);

    $token = $this->sessionStore()->token();
    $this->post('/oauth2/authorize', ['_token' => $token, 'state' => 'not-pending', 'action' => 'approve'])->assertStatus(400);
});
