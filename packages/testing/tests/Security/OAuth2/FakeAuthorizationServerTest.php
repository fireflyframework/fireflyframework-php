<?php

declare(strict_types=1);

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\SignatureInvalidException;
use Firefly\Testing\FireflyTestCase;
use Firefly\Testing\Security\OAuth2\FakeAuthorizationServer;
use Illuminate\Foundation\Application;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Http;

/**
 * The fake provider, driven exactly as the framework will drive it: its front channel through Laravel's test
 * client (the routes are real), its back channel through the Http facade (the fake answers the real calls).
 */
abstract class FakeIdpTestCase extends FireflyTestCase
{
    public const string ISSUER = 'http://localhost/fake-idp';

    public const string VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';

    public const string CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

    public FakeAuthorizationServer $idp;

    protected function defineFireflyEnvironment(Application $app): void
    {
        $this->idp = FakeAuthorizationServer::install($app, self::ISSUER);
    }

    /** A code the fake issued for /cb, with the RFC 7636 appendix-B challenge and the given nonce. */
    public function codeFor(?string $nonce = 'n-1', string $scope = 'openid profile email'): string
    {
        $response = $this->get('/fake-idp/authorize?'.http_build_query(array_filter([
            'response_type' => 'code', 'client_id' => FakeAuthorizationServer::CLIENT_ID, 'redirect_uri' => 'http://localhost/cb',
            'scope' => $scope, 'state' => 's-1', 'nonce' => $nonce, 'code_challenge' => self::CHALLENGE, 'code_challenge_method' => 'S256',
        ])));
        $response->assertRedirect();
        parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);

        expect($query['state'] ?? null)->toBe('s-1');

        return $this->string($query, 'code');
    }

    /** A string member of a decoded JSON body, or '' — so a test never casts `mixed`. */
    public function string(mixed $json, string $key): string
    {
        $value = is_array($json) ? ($json[$key] ?? null) : null;

        return is_string($value) ? $value : '';
    }

    /** @return array<string, mixed> the token endpoint's JSON */
    public function exchange(string $code, string $verifier = self::VERIFIER, string $redirectUri = 'http://localhost/cb'): array
    {
        /** @var array<string, mixed> $json */
        $json = Http::asForm()->acceptJson()->withBasicAuth(FakeAuthorizationServer::CLIENT_ID, FakeAuthorizationServer::CLIENT_SECRET)
            ->post(self::ISSUER.'/token', ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $redirectUri, 'code_verifier' => $verifier])
            ->json();

        return $json;
    }

    /** @return array<string, mixed> */
    public function decode(string $jwt): array
    {
        /** @var array<string, mixed> $claims */
        $claims = json_decode(json_encode(JWT::decode($jwt, JWK::parseKeySet($this->idp->jwks())), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

        return $claims;
    }
}

uses(FakeIdpTestCase::class);

it('serves discovery and a JWKS over the faked back channel, and leaves every other URL alone', function () {
    /** @var FakeIdpTestCase $this */
    Http::preventStrayRequests();

    $discovery = Http::get(FakeIdpTestCase::ISSUER.'/.well-known/openid-configuration')->json();
    $jwks = Http::get(FakeIdpTestCase::ISSUER.'/jwks')->json();

    expect($discovery)->toMatchArray([
        'issuer' => 'http://localhost/fake-idp',
        'authorization_endpoint' => 'http://localhost/fake-idp/authorize',
        'token_endpoint' => 'http://localhost/fake-idp/token',
        'jwks_uri' => 'http://localhost/fake-idp/jwks',
        'userinfo_endpoint' => 'http://localhost/fake-idp/userinfo',
        'end_session_endpoint' => 'http://localhost/fake-idp/end-session',
        'code_challenge_methods_supported' => ['S256'],
    ])->and($jwks)->toBe($this->idp->jwks())
        ->and($this->idp->jwks()['keys'][0]['kid'])->toBe(FakeAuthorizationServer::KID)
        ->and($this->idp->discoveryRequests)->toBe(1)
        ->and($this->idp->jwksRequests)->toBe(1)
        ->and(fn () => Http::get('https://elsewhere.example.com/anything'))->toThrow(StrayRequestException::class);
});

it('runs the authorization-code grant end to end: a single-use PKCE-checked code, RS256 tokens, a rotating refresh token', function () {
    /** @var FakeIdpTestCase $this */
    $code = $this->codeFor();
    $tokens = $this->exchange($code);

    expect($tokens['token_type'] ?? null)->toBe('Bearer')
        ->and($tokens['expires_in'] ?? null)->toBe(3600)
        ->and($tokens['scope'] ?? null)->toBe('openid profile email')
        ->and($this->idp->lastTokenRequest()['authorization'])->toStartWith('Basic ')
        ->and($this->idp->lastTokenRequest()['form']['code_verifier'] ?? null)->toBe(FakeIdpTestCase::VERIFIER)
        ->and($this->idp->lastAuthorizationRequest()['nonce'] ?? null)->toBe('n-1');

    $idToken = $this->decode($this->string($tokens, 'id_token'));
    expect($idToken)->toMatchArray(['iss' => 'http://localhost/fake-idp', 'sub' => 'ada', 'aud' => 'firefly-app', 'azp' => 'firefly-app', 'nonce' => 'n-1', 'email' => 'ada@example.com', 'name' => 'Ada Lovelace'])
        ->and($this->idp->issuedIdTokens)->toBe([$this->string($tokens, 'id_token')])
        ->and($this->decode($this->string($tokens, 'access_token'))['scope'])->toBe('openid profile email');

    // The code is spent; a wrong verifier and a wrong redirect_uri are refused with the RFC 6749 code.
    expect($this->exchange($code)['error'] ?? null)->toBe('invalid_grant')
        ->and($this->exchange($this->codeFor(), 'not-the-verifier')['error'] ?? null)->toBe('invalid_grant')
        ->and($this->exchange($this->codeFor(), redirectUri: 'http://localhost/other')['error'] ?? null)->toBe('invalid_grant');

    // Refresh rotates: the old token is gone, the new response carries new tokens.
    $refreshed = Http::asForm()->withBasicAuth(FakeAuthorizationServer::CLIENT_ID, FakeAuthorizationServer::CLIENT_SECRET)
        ->post(FakeIdpTestCase::ISSUER.'/token', ['grant_type' => 'refresh_token', 'refresh_token' => $this->string($tokens, 'refresh_token')]);
    $again = Http::asForm()->withBasicAuth(FakeAuthorizationServer::CLIENT_ID, FakeAuthorizationServer::CLIENT_SECRET)
        ->post(FakeIdpTestCase::ISSUER.'/token', ['grant_type' => 'refresh_token', 'refresh_token' => $this->string($tokens, 'refresh_token')]);

    expect($refreshed->json('access_token'))->not->toBe($tokens['access_token'])
        ->and($refreshed->json('refresh_token'))->not->toBe($tokens['refresh_token'])
        ->and($again->json('error'))->toBe('invalid_grant');
});

it('authenticates the client by Basic header or form body, refuses a wrong secret, and a public client only when allowed', function () {
    /** @var FakeIdpTestCase $this */
    $post = Http::asForm()->post(FakeIdpTestCase::ISSUER.'/token', ['grant_type' => 'client_credentials', 'client_id' => FakeAuthorizationServer::CLIENT_ID, 'client_secret' => FakeAuthorizationServer::CLIENT_SECRET, 'scope' => 'orders:read']);
    $wrong = Http::asForm()->withBasicAuth(FakeAuthorizationServer::CLIENT_ID, 'nope')->post(FakeIdpTestCase::ISSUER.'/token', ['grant_type' => 'client_credentials']);
    $public = Http::asForm()->post(FakeIdpTestCase::ISSUER.'/token', ['grant_type' => 'client_credentials', 'client_id' => FakeAuthorizationServer::CLIENT_ID]);

    expect($post->status())->toBe(200)
        ->and($post->json('scope'))->toBe('orders:read')
        ->and($this->decode($this->string($post->json(), 'access_token'))['sub'])->toBe('firefly-app')
        ->and($post->json('id_token'))->toBeNull()
        ->and($post->json('refresh_token'))->toBeNull()
        ->and($wrong->status())->toBe(401)
        ->and($wrong->json('error'))->toBe('invalid_client')
        ->and($public->status())->toBe(401);

    $this->idp->acceptPublicClient();
    expect(Http::asForm()->post(FakeIdpTestCase::ISSUER.'/token', ['grant_type' => 'client_credentials', 'client_id' => FakeAuthorizationServer::CLIENT_ID])->status())->toBe(200);
});

it('refuses a token request that is not a form POST with 400 invalid_request, whatever the client credentials, and still records it', function () {
    /** @var FakeIdpTestCase $this */
    $auth = fn () => Http::withBasicAuth(FakeAuthorizationServer::CLIENT_ID, FakeAuthorizationServer::CLIENT_SECRET);
    $json = $auth()->post(FakeIdpTestCase::ISSUER.'/token', ['grant_type' => 'authorization_code', 'code' => $this->codeFor(), 'redirect_uri' => 'http://localhost/cb', 'code_verifier' => FakeIdpTestCase::VERIFIER]);
    $get = $auth()->get(FakeIdpTestCase::ISSUER.'/token', ['grant_type' => 'client_credentials']);

    // Http::post()'s default JSON body and a GET carrying the parameters in the query string are both what a
    // real provider refuses (RFC 6749 §4.1.3); the hop is recorded with what the client tried.
    expect($json->status())->toBe(400)
        ->and($json->json('error'))->toBe('invalid_request')
        ->and($json->json('id_token'))->toBeNull()
        ->and($get->status())->toBe(400)
        ->and($get->json('error'))->toBe('invalid_request')
        ->and($get->json('access_token'))->toBeNull()
        ->and(array_column($this->idp->tokenRequests, 'grant_type'))->toBe(['authorization_code', 'client_credentials'])
        ->and($this->idp->lastTokenRequest()['authorization'])->toStartWith('Basic ');

    // The shape is refused before any knob: a refusal a test configured does not mask a broken client.
    $this->idp->refuseToken('invalid_grant', 'the fake said no');
    expect($auth()->post(FakeIdpTestCase::ISSUER.'/token', ['grant_type' => 'client_credentials'])->json('error'))->toBe('invalid_request');
    $this->idp->refuseToken('', '');

    // A form body whose Content-Type carries a charset is still a form body, and its parameters are read.
    $charset = $auth()->withBody(http_build_query(['grant_type' => 'client_credentials', 'scope' => 'orders:read']), 'application/x-www-form-urlencoded; charset=UTF-8')
        ->post(FakeIdpTestCase::ISSUER.'/token');
    expect($charset->status())->toBe(200)
        ->and($charset->json('scope'))->toBe('orders:read')
        ->and($this->idp->lastTokenRequest()['form'])->toBe(['grant_type' => 'client_credentials', 'scope' => 'orders:read']);
});

it('answers userinfo for a token it issued and 401 for anything else', function () {
    /** @var FakeIdpTestCase $this */
    $tokens = $this->exchange($this->codeFor());

    $info = Http::withToken($this->string($tokens, 'access_token'))->get(FakeIdpTestCase::ISSUER.'/userinfo');
    $bad = Http::withToken('nope')->get(FakeIdpTestCase::ISSUER.'/userinfo');

    expect($info->status())->toBe(200)
        ->and($info->json())->toMatchArray(['sub' => 'ada', 'email' => 'ada@example.com', 'groups' => ['engineering']])
        ->and($bad->status())->toBe(401)
        ->and($this->idp->userInfoRequests)->toBe([$this->string($tokens, 'access_token'), 'nope']);
});

it('renders a consent page when asked, and approves through its form', function () {
    /** @var FakeIdpTestCase $this */
    $this->idp->requireConsent();
    $query = ['response_type' => 'code', 'client_id' => FakeAuthorizationServer::CLIENT_ID, 'redirect_uri' => 'http://localhost/cb', 'scope' => 'openid', 'state' => 's-2', 'nonce' => 'n-2'];

    $page = $this->get('/fake-idp/authorize?'.http_build_query($query));
    $page->assertOk()->assertSee('Allow')->assertSee('asks to sign you in as ada')->assertSee('name="state" value="s-2"', escape: false);

    $approved = $this->post('/fake-idp/authorize', [...$query, 'approve' => '1']);
    $approved->assertRedirect();
    expect((string) $approved->headers->get('Location'))->toStartWith('http://localhost/cb?code=')->toContain('state=s-2')
        ->and(count($this->idp->authorizationRequests))->toBe(2);
});

it('ends a session by redirecting to post_logout_redirect_uri, keeping the state, or shows a signed-out page', function () {
    /** @var FakeIdpTestCase $this */
    $this->get('/fake-idp/end-session?id_token_hint=abc&post_logout_redirect_uri='.urlencode('http://localhost/login?logout').'&state=z')
        ->assertRedirect('http://localhost/login?logout&state=z');
    $this->get('/fake-idp/end-session?id_token_hint=abc')->assertOk()->assertSee('signed out of the provider');

    expect($this->idp->endSessionRequests[0]['id_token_hint'] ?? null)->toBe('abc')
        ->and(count($this->idp->endSessionRequests))->toBe(2);
});

it('turns every knob: a refused authorization, a refused exchange, a foreign signature, overridden claims, no userinfo', function () {
    /** @var FakeIdpTestCase $this */
    $this->idp->refuseAuthorization('access_denied');
    $this->get('/fake-idp/authorize?response_type=code&client_id=firefly-app&redirect_uri=http://localhost/cb&state=s-3')
        ->assertRedirect('http://localhost/cb?error=access_denied&state=s-3');
    $this->idp->refuseAuthorization(null);

    $this->idp->refuseToken('invalid_grant', 'the fake said no');
    $refused = Http::asForm()->withBasicAuth(FakeAuthorizationServer::CLIENT_ID, FakeAuthorizationServer::CLIENT_SECRET)->post(FakeIdpTestCase::ISSUER.'/token', ['grant_type' => 'authorization_code', 'code' => $this->codeFor()]);
    expect($refused->status())->toBe(400)->and($refused->json())->toBe(['error' => 'invalid_grant', 'error_description' => 'the fake said no']);
    $this->idp->refuseToken('', '');

    $this->idp->signWithUnknownKey();
    $foreign = $this->exchange($this->codeFor());
    expect(fn () => $this->decode($this->string($foreign, 'id_token')))->toThrow(SignatureInvalidException::class);
    $this->idp->signWithUnknownKey(false);

    $this->idp->overrideIdTokenClaims(['nonce' => 'wrong', 'email' => null]);
    $overridden = $this->decode($this->string($this->exchange($this->codeFor()), 'id_token'));
    expect($overridden['nonce'])->toBe('wrong')->and($overridden)->not->toHaveKey('email');

    $this->idp->withoutUserInfo();
    expect(Http::get(FakeIdpTestCase::ISSUER.'/.well-known/openid-configuration')->json())->not->toHaveKey('userinfo_endpoint');

    $this->idp->takeDiscoveryDown();
    expect(Http::get(FakeIdpTestCase::ISSUER.'/.well-known/openid-configuration')->status())->toBe(503)
        ->and($this->idp->discoveryRequests)->toBe(2);

    // A document that is served but wrong — the misconfigured-provider case, as distinct from the outage above.
    $this->idp->takeDiscoveryDown(false)->overrideDiscoveryDocument(['issuer' => 'http://localhost/another-idp', 'authorization_endpoint' => null]);
    $wrong = Http::get(FakeIdpTestCase::ISSUER.'/.well-known/openid-configuration');
    expect($wrong->status())->toBe(200)
        ->and($wrong->json())->toMatchArray(['issuer' => 'http://localhost/another-idp', 'token_endpoint' => 'http://localhost/fake-idp/token'])
        ->and($wrong->json())->not->toHaveKey('authorization_endpoint')
        ->and($this->idp->discoveryRequests)->toBe(3);

    $this->idp->overrideDiscoveryDocument([]);
    expect(Http::get(FakeIdpTestCase::ISSUER.'/.well-known/openid-configuration')->json())->toMatchArray(['issuer' => 'http://localhost/fake-idp', 'authorization_endpoint' => 'http://localhost/fake-idp/authorize']);
});
