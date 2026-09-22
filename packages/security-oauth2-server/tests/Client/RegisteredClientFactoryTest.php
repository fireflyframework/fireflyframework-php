<?php

declare(strict_types=1);

use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Security\OAuth2\Server\Client\AuthorizationGrantType;
use Firefly\Security\OAuth2\Server\Client\ClientAuthenticationMethod;
use Firefly\Security\OAuth2\Server\Client\RegisteredClient;
use Firefly\Security\OAuth2\Server\Client\RegisteredClientFactory;
use Firefly\Security\OAuth2\Server\Jose\ClientJwkSet;
use Firefly\Security\OAuth2\Server\Jose\KeyPairGenerator;
use Firefly\Security\OAuth2\Server\Jose\SigningKey;
use Firefly\Security\OAuth2\Server\Settings\AuthorizationServerSettings;
use Firefly\Security\OAuth2\Server\Settings\OAuth2TokenFormat;
use Monolog\Formatter\LineFormatter;
use Monolog\Level;
use Monolog\LogRecord;

/** @param array<string,mixed> $block */
function clientFrom(array $block, ?AuthorizationServerSettings $settings = null): RegisteredClient
{
    return RegisteredClientFactory::fromConfig('web-app', $block, $settings ?? new AuthorizationServerSettings);
}

it('builds a confidential client with Spring\'s defaults: basic auth, code + refresh, the server-wide token settings', function () {
    $client = clientFrom(['client_secret' => '{noop}secret', 'redirect_uris' => ['https://app.test/cb']]);

    expect($client->id)->toBe('web-app')
        ->and($client->clientId)->toBe('web-app')
        ->and($client->clientName)->toBe('web-app')
        ->and($client->clientSecret)->toBe('{noop}secret')
        ->and($client->clientAuthenticationMethods)->toBe([ClientAuthenticationMethod::ClientSecretBasic])
        ->and($client->authorizationGrantTypes)->toBe([AuthorizationGrantType::AuthorizationCode, AuthorizationGrantType::RefreshToken])
        ->and($client->redirectUris)->toBe(['https://app.test/cb'])
        ->and($client->postLogoutRedirectUris)->toBe([])
        ->and($client->scopes)->toBe([])
        ->and($client->isPublic())->toBeFalse()
        ->and($client->clientSettings->requireProofKey)->toBeFalse()
        ->and($client->clientSettings->requireAuthorizationConsent)->toBeTrue()
        ->and($client->tokenSettings->accessTokenTtl)->toBe(300)
        ->and($client->tokenSettings->accessTokenFormat)->toBe(OAuth2TokenFormat::SelfContained)
        ->and($client->tokenSettings->refreshTokenTtl)->toBe(3600)
        ->and($client->tokenSettings->reuseRefreshTokens)->toBeFalse()
        ->and($client->tokenSettings->authorizationCodeTtl)->toBe(300)
        ->and($client->tokenSettings->idTokenTtl)->toBe(1800)
        ->and($client->requiresProofKey(new AuthorizationServerSettings))->toBeTrue()
        ->and($client->requiresProofKey(new AuthorizationServerSettings(requirePkce: false)))->toBeFalse();
});

it('reads every explicit field, a public client, per-client token settings and the jwk_set', function () {
    $client = clientFrom([
        'client_id' => 'spa',
        'client_name' => 'The SPA',
        'client_authentication_methods' => ['none'],
        'authorization_grant_types' => ['authorization_code'],
        'redirect_uris' => ['https://spa.test/cb', 'http://localhost:3000/cb'],
        'post_logout_redirect_uris' => ['https://spa.test/'],
        'scopes' => ['openid', 'profile'],
        'client_settings' => ['require_pkce' => true, 'require_authorization_consent' => false, 'jwk_set' => ['keys' => []]],
        'token_settings' => ['access_token_ttl' => 60, 'access_token_format' => 'reference', 'refresh_token_ttl' => 7200, 'reuse_refresh_tokens' => true, 'authorization_code_ttl' => 120, 'id_token_ttl' => 600],
    ]);

    expect($client->clientId)->toBe('spa')
        ->and($client->clientName)->toBe('The SPA')
        ->and($client->isPublic())->toBeTrue()
        ->and($client->clientSecret)->toBeNull()
        ->and($client->hasRedirectUri('http://localhost:3000/cb'))->toBeTrue()
        ->and($client->hasRedirectUri('https://spa.test/cb/'))->toBeFalse()
        ->and($client->hasPostLogoutRedirectUri('https://spa.test/'))->toBeTrue()
        ->and($client->hasScopes(['openid']))->toBeTrue()
        ->and($client->hasScopes(['openid', 'orders:read']))->toBeFalse()
        ->and($client->supportsGrant(AuthorizationGrantType::RefreshToken))->toBeFalse()
        ->and($client->supportsAuthenticationMethod(ClientAuthenticationMethod::None))->toBeTrue()
        ->and($client->clientSettings->requireAuthorizationConsent)->toBeFalse()
        ->and($client->clientSettings->jwkSet)->toBe(['keys' => []])
        ->and($client->tokenSettings->accessTokenFormat)->toBe(OAuth2TokenFormat::Reference)
        ->and($client->tokenSettings->reuseRefreshTokens)->toBeTrue()
        ->and($client->tokenSettings->idTokenTtl)->toBe(600)
        // A public client needs PKCE even when the server-wide rule is off.
        ->and($client->requiresProofKey(new AuthorizationServerSettings(requirePkce: false)))->toBeTrue()
        ->and($client->requiresProofKey(new AuthorizationServerSettings(requirePkce: false, requireProofKeyForPublicClients: false)))->toBeTrue();
});

it('inherits consent.required from the server when the client says nothing', function () {
    expect(clientFrom(['client_secret' => '{noop}s', 'redirect_uris' => ['https://a.test/cb']], new AuthorizationServerSettings(consentRequired: false))->clientSettings->requireAuthorizationConsent)->toBeFalse();
});

it('masks the secret in a dump, in json_encode and in a Monolog log context', function () {
    $client = clientFrom(['client_secret' => '{bcrypt}$2y$10$abcdefghijklmnopqrstuv', 'redirect_uris' => ['https://a.test/cb']]);

    $dump = print_r($client, true);
    $json = json_encode($client, JSON_THROW_ON_ERROR);
    // Monolog's NormalizerFormatter json-encodes any object it finds in the context; without JsonSerializable it
    // would serialise the public properties — secret included — and never consult __debugInfo.
    $line = (new LineFormatter)->format(new LogRecord(new DateTimeImmutable, 'app', Level::Info, 'client', ['client' => $client]));

    expect($dump)->toContain('web-app')->not->toContain('abcdefghijklmnopqrstuv')
        ->and($json)->toContain('"clientId":"web-app"')->toContain('"clientSecret":"********"')->not->toContain('abcdefghijklmnopqrstuv')
        ->and($line)->toContain('web-app')->not->toContain('abcdefghijklmnopqrstuv')
        ->and(json_encode(clientFrom(['client_authentication_methods' => ['none'], 'redirect_uris' => ['https://a.test/cb']]), JSON_THROW_ON_ERROR))->toContain('"clientSecret":null');
});

it('reads the three client switches with the rule Config::bool() applies, so "off" and "0" mean false', function () {
    $client = clientFrom([
        'client_secret' => '{noop}s',
        'redirect_uris' => ['https://a.test/cb'],
        'client_settings' => ['require_pkce' => 'on', 'require_authorization_consent' => '0'],
        'token_settings' => ['reuse_refresh_tokens' => 'off'],
    ], new AuthorizationServerSettings(reuseRefreshTokens: true));

    expect($client->clientSettings->requireProofKey)->toBeTrue()
        ->and($client->clientSettings->requireAuthorizationConsent)->toBeFalse()
        ->and($client->tokenSettings->reuseRefreshTokens)->toBeFalse();
});

it('refuses at boot every block that could not authenticate or redirect safely, naming the client', function (array $block, string $needle) {
    /** @var array<string, mixed> $block */
    expect(fn () => clientFrom($block))->toThrow(ConfigurationException::class, $needle);
})->with([
    'unknown method' => [['client_secret' => '{noop}s', 'client_authentication_methods' => ['tls'], 'redirect_uris' => ['https://a.test/cb']], 'client_authentication_methods'],
    'unknown grant' => [['client_secret' => '{noop}s', 'authorization_grant_types' => ['password'], 'redirect_uris' => ['https://a.test/cb']], 'authorization_grant_types'],
    'confidential without secret' => [['redirect_uris' => ['https://a.test/cb']], 'client_secret'],
    'secret without an {id} prefix' => [['client_secret' => 'plain', 'redirect_uris' => ['https://a.test/cb']], '{id}'],
    'public with a secret' => [['client_secret' => '{noop}s', 'client_authentication_methods' => ['none'], 'redirect_uris' => ['https://a.test/cb']], 'none'],
    'code without redirect uris' => [['client_secret' => '{noop}s'], 'redirect_uris'],
    'relative redirect uri' => [['client_secret' => '{noop}s', 'redirect_uris' => ['/cb']], 'absolute'],
    'redirect uri with a fragment' => [['client_secret' => '{noop}s', 'redirect_uris' => ['https://a.test/cb#x']], 'fragment'],
    'private_key_jwt without jwk_set' => [['client_authentication_methods' => ['private_key_jwt'], 'redirect_uris' => ['https://a.test/cb']], 'jwk_set'],
    'bad format' => [['client_secret' => '{noop}s', 'redirect_uris' => ['https://a.test/cb'], 'token_settings' => ['access_token_format' => 'jwt']], 'access_token_format'],
    'bad ttl' => [['client_secret' => '{noop}s', 'redirect_uris' => ['https://a.test/cb'], 'token_settings' => ['access_token_ttl' => '300']], 'access_token_ttl'],
    'reuse_refresh_tokens not a bool' => [['client_secret' => '{noop}s', 'redirect_uris' => ['https://a.test/cb'], 'token_settings' => ['reuse_refresh_tokens' => 'nonsense']], 'token_settings.reuse_refresh_tokens'],
    'reuse_refresh_tokens garbage, the message states the rule' => [['client_secret' => '{noop}s', 'redirect_uris' => ['https://a.test/cb'], 'token_settings' => ['reuse_refresh_tokens' => 'maybe']], 'must be true or false'],
    'require_pkce not a bool' => [['client_secret' => '{noop}s', 'redirect_uris' => ['https://a.test/cb'], 'client_settings' => ['require_pkce' => 'nonsense']], 'client_settings.require_pkce'],
    'require_authorization_consent not a bool' => [['client_secret' => '{noop}s', 'redirect_uris' => ['https://a.test/cb'], 'client_settings' => ['require_authorization_consent' => []]], 'client_settings.require_authorization_consent'],
    'scopes not a list' => [['client_secret' => '{noop}s', 'redirect_uris' => ['https://a.test/cb'], 'scopes' => 'openid'], 'scopes'],
]);

it('accepts a client-credentials-only client without redirect URIs', function () {
    $client = clientFrom(['client_secret' => '{noop}s', 'authorization_grant_types' => ['client_credentials'], 'scopes' => ['orders:read']]);

    expect($client->redirectUris)->toBe([])
        ->and($client->supportsGrant(AuthorizationGrantType::ClientCredentials))->toBeTrue();
});

/**
 * A private_key_jwt client whose `jwk_set` holds the given keys.
 *
 * @param  list<mixed>  $keys
 */
function jwtClientWith(array $keys): RegisteredClient
{
    return clientFrom(['client_authentication_methods' => ['private_key_jwt'], 'authorization_grant_types' => ['client_credentials'], 'client_settings' => ['jwk_set' => ['keys' => $keys]]]);
}

it('refuses at boot, naming the client and the key, a jwk_set php-jwt could only refuse on the first token request', function (array $keys, string $needle) {
    /** @var list<mixed> $keys */
    expect(fn () => jwtClientWith($keys))->toThrow(ConfigurationException::class, $needle)
        ->and(fn () => jwtClientWith($keys))->toThrow(ConfigurationException::class, 'Client [web-app]');
})->with([
    'no keys at all' => [[], 'at least one key'],
    'a key that is not a map' => [['-----BEGIN PUBLIC KEY-----'], 'keys[0] must be a JWK'],
    'a symmetric key' => [[['kty' => 'oct', 'kid' => 'k', 'k' => 'c2VjcmV0']], 'kty must be RSA or EC'],
    'an HMAC alg on an RSA key' => [[['kty' => 'RSA', 'kid' => 'k', 'alg' => 'HS256', 'n' => 'AQ', 'e' => 'AQAB']], 'alg must be one of RS256, RS384, RS512, ES256, ES384'],
    'an EC key without alg on a curve with no default' => [[['kty' => 'EC', 'kid' => 'k', 'crv' => 'secp256k1', 'x' => 'AQ', 'y' => 'AQ']], 'none is named, and none follows from kty EC on the curve `secp256k1`'],
    'a second key without kid' => [[['kty' => 'RSA', 'kid' => 'a', 'n' => 'AQ', 'e' => 'AQAB'], ['kty' => 'RSA', 'n' => 'AQ', 'e' => 'AQAB']], 'keys[1]: kid is required when the set holds more than one key'],
    'an empty kid' => [[['kty' => 'RSA', 'kid' => '', 'n' => 'AQ', 'e' => 'AQAB']], 'kid must be a non-empty string'],
    'two keys under one kid' => [[['kty' => 'RSA', 'kid' => 'a', 'n' => 'AQ', 'e' => 'AQAB'], ['kty' => 'RSA', 'kid' => 'a', 'n' => 'AQ', 'e' => 'AQAB']], 'keys[1]: kid [a] is already used by keys[0]'],
    'an RSA key without its modulus' => [[['kty' => 'RSA', 'kid' => 'k', 'e' => 'AQAB']], 'keys[0] could not be loaded: RSA keys must contain values for both "n" and "e"'],
    'an EC key without its coordinates' => [[['kty' => 'EC', 'kid' => 'k', 'crv' => 'P-256']], 'keys[0] could not be loaded: x and y not set'],
]);

it('accepts a jwk_set whose keys omit the OPTIONAL alg, and a single key without kid, and holds the document as it was given', function () {
    $rsa = SigningKey::fromPem(KeyPairGenerator::generate('RS256'), 'RS256', 'rsa')->jwk;
    $ec = SigningKey::fromPem(KeyPairGenerator::generate('ES256'), 'ES256', 'ec')->jwk;
    unset($rsa['alg'], $ec['alg']);
    $single = $rsa;
    unset($single['kid']);

    expect(jwtClientWith([$rsa, $ec])->clientSettings->jwkSet)->toBe(['keys' => [$rsa, $ec]])
        ->and(jwtClientWith([$single])->clientSettings->jwkSet)->toBe(['keys' => [$single]])
        ->and(ClientJwkSet::defaultAlgorithm($rsa))->toBe('RS256')
        ->and(ClientJwkSet::defaultAlgorithm($ec))->toBe('ES256')
        ->and(ClientJwkSet::defaultAlgorithm(['kty' => 'EC', 'crv' => 'P-384']))->toBe('ES384')
        ->and(ClientJwkSet::defaultAlgorithm(['kty' => 'oct']))->toBeNull()
        ->and(array_keys(ClientJwkSet::parse(['keys' => [$rsa, $ec]])))->toBe(['rsa', 'ec'])
        ->and(array_keys(ClientJwkSet::parse(['keys' => [$single]])))->toBe([0]); // its position, as php-jwt keys a kid-less entry
});

it('does not hold a client that cannot use private_key_jwt to the jwk_set rules: the set is carried, never read', function () {
    $client = clientFrom(['client_authentication_methods' => ['none'], 'redirect_uris' => ['https://a.test/cb'], 'client_settings' => ['jwk_set' => ['keys' => [['kty' => 'oct', 'k' => 'c2VjcmV0']]]]]);

    expect($client->clientSettings->jwkSet)->toBe(['keys' => [['kty' => 'oct', 'k' => 'c2VjcmV0']]]);
});
