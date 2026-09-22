<?php

declare(strict_types=1);

use Firefly\Security\OAuth2\Server\Jose\JwtGenerator;
use Firefly\Security\OAuth2\Server\Pkce\ProofKey;
use Firefly\Tests\Browser\Support\OAuth2ServerBrowserTestCase;

pest()->extend(OAuth2ServerBrowserTestCase::class);

/**
 * The login form's submit button as a CSS selector rather than as its label. The plugin guesses a locator
 * from a bare string and falls back to a NON-STRICT text match, and the framework's login page carries the
 * words "Sign in" twice — once as the `<h1>` and once on `<button type="submit" class="btn">` — so the guess
 * lands on the heading, whose click submits nothing and leaves the browser on /login with the fields still
 * filled. The consent page needs no such help: "Allow" and "Deny" each appear exactly once on it.
 */
const SIGN_IN_BUTTON = 'button[type="submit"]';

/**
 * @param  array<string,string>  $extra
 */
function authorizeUrl(string $redirectUri, ProofKey $proof, string $state, string $nonce, array $extra = []): string
{
    return '/oauth2/authorize?'.http_build_query($extra + [
        'response_type' => 'code',
        'client_id' => OAuth2ServerBrowserTestCase::CLIENT_ID,
        'redirect_uri' => $redirectUri,
        'scope' => 'openid profile',
        'state' => $state,
        'nonce' => $nonce,
        'code_challenge' => $proof->challenge(),
        'code_challenge_method' => 'S256',
    ]);
}

it('drives the whole authorization-code flow in Chromium: login page, consent, the redirect back with the code, then the exchange and a protected API call in-process', function (): void {
    /** @var OAuth2ServerBrowserTestCase $this */
    $start = visit('/browser-fixture/rp/start');
    $start->assertSee('Relying party')->assertNoJavaScriptErrors()->screenshot(filename: 'oauth2-rp-start');
    $redirectUri = $this->registerRelyingParty($this->originOf($start->url()));

    $proof = ProofKey::generate();
    $state = 'st-'.bin2hex(random_bytes(4));
    $nonce = 'n-'.bin2hex(random_bytes(4));

    // Hop 1: the authorization request from an anonymous browser lands on the framework's login page.
    $login = visit(authorizeUrl($redirectUri, $proof, $state, $nonce));
    $login->assertPathIs('/login')
        ->assertSee('Sign in')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'oauth2-login');

    // Hop 2: signing in returns to the authorization request, which now shows the consent page.
    $consent = $login->fill('username', 'ada')->fill('password', 'secret')->press(SIGN_IN_BUTTON);
    $consent->assertPathIs('/oauth2/authorize')
        ->assertQueryStringHas('client_id', OAuth2ServerBrowserTestCase::CLIENT_ID)
        ->assertQueryStringHas('state', $state)
        ->assertQueryStringHas('nonce', $nonce)
        ->assertSee('Allow access?')
        ->assertSee('The browser relying party')
        ->assertSee('openid')
        ->assertSee('profile')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'oauth2-consent');

    // Hop 3: approval redirects to the relying party with the code and the echoed state.
    $callback = $consent->press('Allow');
    $callback->assertPathIs(OAuth2ServerBrowserTestCase::CALLBACK_PATH)
        ->assertQueryStringHas('code')
        ->assertQueryStringHas('state', $state)
        ->assertQueryStringMissing('error')
        ->assertSee('Authorization code received')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'oauth2-callback');

    parse_str((string) parse_url($callback->url(), PHP_URL_QUERY), $query);
    $parameter = $query['code'] ?? null;
    $code = is_string($parameter) ? $parameter : '';
    expect($code)->not->toBe('');

    // In-process, as the relying party's back channel: the exchange with the verifier and the client secret.
    $tokens = $this->withBasicAuth(OAuth2ServerBrowserTestCase::CLIENT_ID, OAuth2ServerBrowserTestCase::CLIENT_SECRET)
        ->post('/oauth2/token', ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $redirectUri, 'code_verifier' => $proof->verifier], ['Accept' => 'application/json']);
    $this->flushHeaders();
    $tokens->assertOk()->assertJson(['token_type' => 'Bearer', 'scope' => 'openid profile']);
    /** @var array<string,mixed> $body */
    $body = $tokens->json();
    expect($body)->toHaveKeys(['access_token', 'refresh_token', 'id_token']);
    /** @var string $accessToken */
    $accessToken = $body['access_token'];
    /** @var string $idToken */
    $idToken = $body['id_token'];

    $id = $this->app()->make(JwtGenerator::class)->decode($idToken);
    expect($id)->toMatchArray(['sub' => 'ada', 'aud' => [OAuth2ServerBrowserTestCase::CLIENT_ID], 'nonce' => $nonce]);

    // The resource-server filter of the same application accepts the JWT; without it the API is closed. The
    // bearer is sent as an API client sends it — from a process that holds no session. The plugin serves
    // Chromium from THIS process, so the session manager still holds the store the browser signed in on, and
    // Laravel's Store::start() MERGES what the handler returns onto the attributes the store already carries:
    // without forgetting it, the persistence filter at -94 would name ada with ROLE_USER and the
    // resource-server filter at -85 would never look at the token. The capstone suite forgets it for the same
    // reason, and every in-process request from here on is that fresh process.
    $this->forgetSession();
    $this->withHeader('Authorization', 'Bearer '.$accessToken)->getJson('/api/browser-fixture/profile')
        ->assertOk()
        ->assertJson(['sub' => 'ada', 'authorities' => ['SCOPE_openid', 'SCOPE_profile']]);
    $this->flushHeaders();
    $this->getJson('/api/browser-fixture/profile')->assertStatus(401);

    $this->withBasicAuth(OAuth2ServerBrowserTestCase::CLIENT_ID, OAuth2ServerBrowserTestCase::CLIENT_SECRET)
        ->post('/oauth2/introspect', ['token' => $accessToken], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJson(['active' => true, 'sub' => 'ada', 'client_id' => OAuth2ServerBrowserTestCase::CLIENT_ID]);
    $this->flushHeaders();

    // A reused code is refused and the tokens it issued are revoked.
    $this->withBasicAuth(OAuth2ServerBrowserTestCase::CLIENT_ID, OAuth2ServerBrowserTestCase::CLIENT_SECRET)
        ->post('/oauth2/token', ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $redirectUri, 'code_verifier' => $proof->verifier], ['Accept' => 'application/json'])
        ->assertStatus(400)
        ->assertJson(['error' => 'invalid_grant']);
    $this->flushHeaders();
    $this->withHeader('Authorization', 'Bearer '.$accessToken)->getJson('/userinfo')->assertStatus(401);
    $this->flushHeaders();
});

it('bounces back to the relying party with access_denied and the state when the person denies', function (): void {
    /** @var OAuth2ServerBrowserTestCase $this */
    $start = visit('/browser-fixture/rp/start');
    $redirectUri = $this->registerRelyingParty($this->originOf($start->url()));
    $state = 'st-'.bin2hex(random_bytes(4));

    $consent = visit(authorizeUrl($redirectUri, ProofKey::generate(), $state, 'n-1'))
        ->assertPathIs('/login')
        ->fill('username', 'ada')->fill('password', 'secret')->press(SIGN_IN_BUTTON);
    $consent->assertPathIs('/oauth2/authorize')->assertSee('Allow access?');

    $consent->press('Deny')
        ->assertPathIs(OAuth2ServerBrowserTestCase::CALLBACK_PATH)
        ->assertQueryStringHas('error', 'access_denied')
        ->assertQueryStringHas('state', $state)
        ->assertQueryStringMissing('code')
        ->assertSee('Authorization refused')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'oauth2-denied');
});

it('renders the login and consent pages in dark mode and at phone width', function (): void {
    /** @var OAuth2ServerBrowserTestCase $this */
    $start = visit('/browser-fixture/rp/start');
    $redirectUri = $this->registerRelyingParty($this->originOf($start->url()));
    $url = authorizeUrl($redirectUri, ProofKey::generate(), 'st-dark', 'n-dark');

    visit($url)->inDarkMode()->assertPathIs('/login')->assertSee('Sign in')->assertNoJavaScriptErrors()->screenshot(filename: 'oauth2-login-dark');

    $consent = visit($url)->on()->mobile()->assertPathIs('/login')->fill('username', 'ada')->fill('password', 'secret')->press(SIGN_IN_BUTTON);
    $consent->assertPathIs('/oauth2/authorize')->assertSee('Allow access?')->assertNoJavaScriptErrors()->screenshot(filename: 'oauth2-consent-mobile');
});
