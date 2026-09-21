<?php

declare(strict_types=1);

use Firebase\JWT\JWT;
use Firefly\Security\OAuth2\Server\Client\ClientAuthenticationMethod;
use Firefly\Security\OAuth2\Server\Client\ClientAuthenticator;
use Firefly\Security\OAuth2\Server\Client\InMemoryRegisteredClientRepository;
use Firefly\Security\OAuth2\Server\Error\OAuth2AuthenticationException;
use Firefly\Security\OAuth2\Server\Jose\KeyPairGenerator;
use Firefly\Security\OAuth2\Server\Jose\SigningKey;
use Firefly\Security\OAuth2\Server\Settings\AuthorizationServerSettings;
use Firefly\Security\Password\BcryptPasswordEncoder;
use Firefly\Security\Password\DelegatingPasswordEncoder;
use Firefly\Security\Password\NoOpPasswordEncoder;
use Illuminate\Http\Request;

function clientAuthenticator(?SigningKey $jwtClientKey = null): ClientAuthenticator
{
    $settings = new AuthorizationServerSettings(issuer: 'https://issuer.test');
    $clients = [
        'web app' => ['client_secret' => '{noop}web-secret', 'client_authentication_methods' => ['client_secret_basic', 'client_secret_post'], 'redirect_uris' => ['https://a.test/cb']],
        'basic-only' => ['client_secret' => '{noop}s', 'redirect_uris' => ['https://a.test/cb']],
        'spa' => ['client_authentication_methods' => ['none'], 'authorization_grant_types' => ['authorization_code'], 'redirect_uris' => ['https://a.test/cb']],
    ];
    if ($jwtClientKey !== null) {
        $clients['jwt-client'] = ['client_authentication_methods' => ['private_key_jwt'], 'authorization_grant_types' => ['client_credentials'], 'client_settings' => ['jwk_set' => ['keys' => [$jwtClientKey->jwk]]]];
    }
    $encoder = new DelegatingPasswordEncoder('bcrypt', ['bcrypt' => new BcryptPasswordEncoder, 'noop' => new NoOpPasswordEncoder]);

    return new ClientAuthenticator(InMemoryRegisteredClientRepository::fromConfig($clients, $settings), $encoder, $settings);
}

/**
 * @param  array<string,mixed>  $body
 * @param  array<string,string>  $headers
 */
function tokenRequest(array $body = [], array $headers = []): Request
{
    $request = Request::create('/oauth2/token', 'POST', $body);
    foreach ($headers as $name => $value) {
        $request->headers->set($name, $value);
    }

    return $request;
}

/**
 * @return array<string,string>
 */
function basic(string $id, string $secret): array
{
    return ['Authorization' => 'Basic '.base64_encode(rawurlencode($id).':'.rawurlencode($secret))];
}

it('authenticates client_secret_basic with RFC 6749 §2.3.1 form-decoding, and client_secret_post', function () {
    $authenticator = clientAuthenticator();

    $viaBasic = $authenticator->authenticate(tokenRequest([], basic('web app', 'web-secret')), false);
    $viaPost = $authenticator->authenticate(tokenRequest(['client_id' => 'web app', 'client_secret' => 'web-secret']), false);

    expect($viaBasic->client->clientId)->toBe('web app')
        ->and($viaBasic->method)->toBe(ClientAuthenticationMethod::ClientSecretBasic)
        ->and($viaPost->method)->toBe(ClientAuthenticationMethod::ClientSecretPost)
        ->and(ClientAuthenticator::usedBasic(tokenRequest([], basic('web app', 'x'))))->toBeTrue()
        ->and(ClientAuthenticator::challengeHeaders(tokenRequest([], basic('web app', 'x'))))->toBe(['WWW-Authenticate' => 'Basic realm="oauth2"'])
        ->and(ClientAuthenticator::challengeHeaders(tokenRequest(['client_id' => 'x'])))->toBe([]);
});

it('refuses, as invalid_client 401, a wrong secret, an unknown client, a method the client does not allow, and no credentials at all', function (Request $request, string $needle) {
    try {
        clientAuthenticator()->authenticate($request, false);
        throw new LogicException('not refused');
    } catch (OAuth2AuthenticationException $e) {
        expect($e->error()->errorCode)->toBe('invalid_client')
            ->and($e->status())->toBe(401)
            ->and($e->getMessage())->toContain($needle)
            ->and($e->getMessage())->not->toContain('web-secret');
    }
})->with([
    'wrong secret' => [tokenRequest([], basic('web app', 'nope')), 'invalid_client'],
    'unknown client' => [tokenRequest([], basic('ghost', 'nope')), 'invalid_client'],
    'basic-only via post' => [tokenRequest(['client_id' => 'basic-only', 'client_secret' => 's']), 'client_secret_post'],
    'nothing' => [tokenRequest(), 'no client authentication'],
    'public client where public is not allowed' => [tokenRequest(['client_id' => 'spa']), 'public'],
]);

it('refuses two methods in one request as invalid_request', function () {
    expect(fn () => clientAuthenticator()->authenticate(tokenRequest(['client_id' => 'web app', 'client_secret' => 'web-secret'], basic('web app', 'web-secret')), false))
        ->toThrow(OAuth2AuthenticationException::class, 'invalid_request');
});

it('accepts a public client by client_id alone where public clients are allowed', function () {
    $authentication = clientAuthenticator()->authenticate(tokenRequest(['client_id' => 'spa']), true);

    expect($authentication->method)->toBe(ClientAuthenticationMethod::None)
        ->and($authentication->client->isPublic())->toBeTrue();
});

it('verifies a private_key_jwt assertion against the client\'s jwk_set: iss and sub the client, aud the token endpoint or the issuer', function () {
    $key = SigningKey::fromPem(KeyPairGenerator::generate('RS256'), 'RS256', 'client-key');
    $authenticator = clientAuthenticator($key);
    $assert = fn (array $claims): string => JWT::encode($claims + ['iss' => 'jwt-client', 'sub' => 'jwt-client', 'aud' => 'https://issuer.test/oauth2/token', 'exp' => time() + 60, 'jti' => bin2hex(random_bytes(8))], $key->key, 'RS256', 'client-key');

    $ok = $authenticator->authenticate(tokenRequest(['client_assertion_type' => ClientAuthenticator::JWT_BEARER, 'client_assertion' => $assert([])]), false);
    expect($ok->method)->toBe(ClientAuthenticationMethod::PrivateKeyJwt)
        ->and($ok->client->clientId)->toBe('jwt-client');

    $viaIssuer = $authenticator->authenticate(tokenRequest(['client_assertion_type' => ClientAuthenticator::JWT_BEARER, 'client_assertion' => $assert(['aud' => 'https://issuer.test'])]), false);
    expect($viaIssuer->client->clientId)->toBe('jwt-client');

    $stranger = SigningKey::fromPem(KeyPairGenerator::generate('RS256'), 'RS256', 'client-key');
    $forged = JWT::encode(['iss' => 'jwt-client', 'sub' => 'jwt-client', 'aud' => 'https://issuer.test/oauth2/token', 'exp' => time() + 60], $stranger->key, 'RS256', 'client-key');

    expect(fn () => $authenticator->authenticate(tokenRequest(['client_assertion_type' => ClientAuthenticator::JWT_BEARER, 'client_assertion' => $forged]), false))->toThrow(OAuth2AuthenticationException::class, 'invalid_client')
        ->and(fn () => $authenticator->authenticate(tokenRequest(['client_assertion_type' => ClientAuthenticator::JWT_BEARER, 'client_assertion' => $assert(['aud' => 'https://elsewhere.test'])]), false))->toThrow(OAuth2AuthenticationException::class, 'invalid_client')
        ->and(fn () => $authenticator->authenticate(tokenRequest(['client_assertion_type' => ClientAuthenticator::JWT_BEARER, 'client_assertion' => $assert(['iss' => 'web app'])]), false))->toThrow(OAuth2AuthenticationException::class, 'invalid_client')
        ->and(fn () => $authenticator->authenticate(tokenRequest(['client_assertion_type' => 'urn:something:else', 'client_assertion' => $assert([])]), false))->toThrow(OAuth2AuthenticationException::class, 'invalid_client');
});
