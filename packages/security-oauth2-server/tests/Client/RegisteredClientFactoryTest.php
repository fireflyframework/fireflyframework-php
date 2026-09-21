<?php

declare(strict_types=1);

use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Security\OAuth2\Server\Client\AuthorizationGrantType;
use Firefly\Security\OAuth2\Server\Client\ClientAuthenticationMethod;
use Firefly\Security\OAuth2\Server\Client\RegisteredClient;
use Firefly\Security\OAuth2\Server\Client\RegisteredClientFactory;
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
