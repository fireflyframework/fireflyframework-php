<?php

declare(strict_types=1);

use Firefly\Security\OAuth2\Client\OAuth2ClientSettings;
use Firefly\Security\OAuth2\Client\Registration\AuthorizationGrantType;
use Firefly\Security\OAuth2\Client\Registration\ClientAuthenticationMethod;
use Firefly\Security\OAuth2\Client\Registration\ClientRegistration;
use Firefly\Security\OAuth2\Client\Registration\ProviderDetails;
use Firefly\Security\OAuth2\Client\Token\DefaultOAuth2AccessTokenResponseClient;
use Firefly\Security\OAuth2\Client\Token\OAuth2AuthorizationException;
use Firefly\Security\OAuth2\Client\Token\OAuth2RefreshToken;
use Firefly\Testing\Security\OAuth2\FakeAuthorizationServer;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;

uses(TestCase::class);

const TOKEN_CLIENT_ISSUER = 'https://idp.example.test/fake';

/** @param list<string> $scopes */
function tokenClientRegistration(ClientAuthenticationMethod $method = ClientAuthenticationMethod::ClientSecretBasic, array $scopes = ['openid', 'profile'], string $tokenUri = TOKEN_CLIENT_ISSUER.'/token'): ClientRegistration
{
    return new ClientRegistration(
        'fake', FakeAuthorizationServer::CLIENT_ID, $method === ClientAuthenticationMethod::None ? '' : FakeAuthorizationServer::CLIENT_SECRET,
        $method, AuthorizationGrantType::AuthorizationCode, '{baseUrl}/cb', $scopes, 'Fake',
        new ProviderDetails(TOKEN_CLIENT_ISSUER.'/authorize', $tokenUri, TOKEN_CLIENT_ISSUER.'/jwks', TOKEN_CLIENT_ISSUER.'/userinfo', 'sub', TOKEN_CLIENT_ISSUER),
    );
}

function tokenClient(): DefaultOAuth2AccessTokenResponseClient
{
    return new DefaultOAuth2AccessTokenResponseClient(app(), new OAuth2ClientSettings);
}

/**
 * A fresh fake on a FRESH Http factory: Factory::fake() merges stub callbacks and the first non-null answer
 * wins, so a second fake on the same factory would never be asked. Http::swap() rebinds the container instance
 * and the facade root together, which is what the token client (container) and RemoteJwksProvider (facade) read.
 */
function fakeIdp(): FakeAuthorizationServer
{
    $http = new HttpFactory;
    Http::swap($http);

    return (new FakeAuthorizationServer(TOKEN_CLIENT_ISSUER))->fakeBackChannel($http);
}

it('exchanges a code with Basic client authentication, the verifier and the exact redirect uri, and parses the response', function () {
    $idp = fakeIdp();
    $code = $idp->issueAuthorizationCode('https://app.example.test/cb', ['openid', 'profile'], 'n-1', 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM');

    $response = tokenClient()->authorizationCode(tokenClientRegistration(), $code, 'https://app.example.test/cb', 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk');

    expect($response->accessToken->tokenValue)->toBe($idp->issuedAccessTokens[0])
        ->and($response->accessToken->scopes)->toBe(['openid', 'profile'])
        ->and($response->accessToken->expiresAt)->toBe($response->accessToken->issuedAt + 3600)
        ->and($response->accessToken->isExpired($response->accessToken->issuedAt + 3599))->toBeFalse()
        ->and($response->accessToken->isExpired($response->accessToken->issuedAt + 3599, 60))->toBeTrue()
        ->and($response->refreshToken?->tokenValue)->toStartWith('rt-')
        ->and($response->additionalParameters)->toHaveKey('id_token')
        ->and($response->additionalParameters)->not->toHaveKey('access_token')
        ->and($idp->lastTokenRequest()['form'])->toMatchArray(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => 'https://app.example.test/cb', 'code_verifier' => 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk'])
        ->and($idp->lastTokenRequest()['form'])->not->toHaveKey('client_secret')
        ->and($idp->lastTokenRequest()['authorization'])->toBe('Basic '.base64_encode(FakeAuthorizationServer::CLIENT_ID.':'.FakeAuthorizationServer::CLIENT_SECRET));
    Http::assertSent(static fn (Request $request): bool => $request->url() === TOKEN_CLIENT_ISSUER.'/token' && $request->isForm() && $request->hasHeader('Accept', 'application/json'));
});

it('presents the client in the body for client_secret_post, and only its id for a public client', function () {
    $idp = fakeIdp()->acceptPublicClient();

    tokenClient()->authorizationCode(tokenClientRegistration(ClientAuthenticationMethod::ClientSecretPost), $idp->issueAuthorizationCode('https://app/cb', ['openid']), 'https://app/cb', null);
    $post = $idp->lastTokenRequest();
    tokenClient()->authorizationCode(tokenClientRegistration(ClientAuthenticationMethod::None), $idp->issueAuthorizationCode('https://app/cb', ['openid']), 'https://app/cb', null);
    $none = $idp->lastTokenRequest();

    expect($post['authorization'])->toBeNull()
        ->and($post['form'])->toMatchArray(['client_id' => FakeAuthorizationServer::CLIENT_ID, 'client_secret' => FakeAuthorizationServer::CLIENT_SECRET])
        ->and($post['form'])->not->toHaveKey('code_verifier')
        ->and($none['authorization'])->toBeNull()
        ->and($none['form']['client_id'] ?? null)->toBe(FakeAuthorizationServer::CLIENT_ID)
        ->and($none['form'])->not->toHaveKey('client_secret');
});

it('refreshes and fetches client credentials, sending the scopes it was asked for', function () {
    $idp = fakeIdp();
    $first = tokenClient()->authorizationCode(tokenClientRegistration(), $idp->issueAuthorizationCode('https://app/cb', ['openid', 'profile']), 'https://app/cb', null);

    $refreshed = tokenClient()->refreshToken(tokenClientRegistration(), $first->refreshToken ?? new OAuth2RefreshToken('none', 0), ['openid', 'profile']);
    $service = tokenClient()->clientCredentials(tokenClientRegistration(scopes: ['orders:read']), ['orders:read']);

    expect($refreshed->accessToken->tokenValue)->not->toBe($first->accessToken->tokenValue)
        ->and($refreshed->refreshToken?->tokenValue)->not->toBe($first->refreshToken?->tokenValue)
        ->and($idp->tokenRequests[1]['form'])->toMatchArray(['grant_type' => 'refresh_token', 'scope' => 'openid profile'])
        ->and($service->accessToken->scopes)->toBe(['orders:read'])
        ->and($service->refreshToken)->toBeNull()
        ->and($idp->tokenRequests[2]['form'])->toMatchArray(['grant_type' => 'client_credentials', 'scope' => 'orders:read']);
});

it('maps an error response to its RFC 6749 code, and a transport or shape failure to invalid_token_response — never naming the secret or the code', function () {
    Http::fake([
        'https://down.example.test/token' => Http::response(['error' => 'invalid_grant', 'error_description' => 'code expired', 'error_uri' => 'https://idp/errors'], 400),
        'https://unauth.example.test/token' => Http::response(['error' => 'invalid_client'], 401),
        'https://html.example.test/token' => Http::response('<html>', 502, ['Content-Type' => 'text/html']),
        'https://notbearer.example.test/token' => Http::response(['access_token' => 'x', 'token_type' => 'mac'], 200),
        'https://noaccess.example.test/token' => Http::response(['token_type' => 'Bearer'], 200),
        'https://gone.example.test/token' => Http::failedConnection(),
    ]);
    $attempt = static function (string $tokenUri): OAuth2AuthorizationException {
        try {
            tokenClient()->authorizationCode(tokenClientRegistration(tokenUri: $tokenUri), 'the-code-value', 'https://app/cb', 'the-verifier-value');
        } catch (OAuth2AuthorizationException $e) {
            return $e;
        }
        throw new RuntimeException('expected a refusal');
    };

    $grant = $attempt('https://down.example.test/token');
    expect($grant->error->errorCode)->toBe('invalid_grant')
        ->and($grant->error->description)->toBe('code expired')
        ->and($grant->error->uri)->toBe('https://idp/errors')
        ->and($grant->registrationId)->toBe('fake')
        ->and($grant->errorCode())->toBe('OAUTH2_INVALID_GRANT')
        ->and($grant->httpStatus())->toBe(503)
        ->and($grant->getMessage())->toContain('[fake]')->toContain('invalid_grant')
        ->and($attempt('https://unauth.example.test/token')->error->errorCode)->toBe('invalid_client')
        ->and($attempt('https://html.example.test/token')->error->errorCode)->toBe('invalid_token_response')
        ->and($attempt('https://html.example.test/token')->error->description)->toContain('502')
        ->and($attempt('https://notbearer.example.test/token')->error->errorCode)->toBe('invalid_token_response')
        ->and($attempt('https://noaccess.example.test/token')->error->errorCode)->toBe('invalid_token_response')
        ->and($attempt('https://gone.example.test/token')->error->errorCode)->toBe('invalid_token_response')
        ->and($attempt('https://gone.example.test/token')->getPrevious())->not->toBeNull();

    foreach (['https://down.example.test/token', 'https://gone.example.test/token'] as $uri) {
        $e = $attempt($uri);
        $chain = '';
        for ($t = $e; $t !== null; $t = $t->getPrevious()) {
            $chain .= $t->getMessage();
        }
        expect($chain)->not->toContain(FakeAuthorizationServer::CLIENT_SECRET)->not->toContain('the-code-value')->not->toContain('the-verifier-value');
    }
});
