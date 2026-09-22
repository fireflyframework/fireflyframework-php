<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Security\OAuth2\Client\Discovery\OidcDiscovery;
use Firefly\Security\OAuth2\Client\OAuth2ClientSettings;
use Firefly\Security\OAuth2\Client\Registration\AuthorizationGrantType;
use Firefly\Security\OAuth2\Client\Registration\ClientAuthenticationMethod;
use Firefly\Security\OAuth2\Client\Registration\OAuth2ClientProperties;
use Firefly\Security\OAuth2\Client\Registration\OAuth2ClientPropertiesMapper;
use Firefly\Security\OAuth2\Client\Registration\PropertiesClientRegistrationRepository;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Config\Repository;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;

uses(TestCase::class);

/** @return array<string, mixed> a Keycloak-shaped discovery document for the issuer */
function mapperDiscoveryDocument(string $issuer): array
{
    return [
        'issuer' => $issuer,
        'authorization_endpoint' => $issuer.'/protocol/openid-connect/auth',
        'token_endpoint' => $issuer.'/protocol/openid-connect/token',
        'jwks_uri' => $issuer.'/protocol/openid-connect/certs',
        'userinfo_endpoint' => $issuer.'/protocol/openid-connect/userinfo',
        'end_session_endpoint' => $issuer.'/protocol/openid-connect/logout',
    ];
}

/**
 * @param  array<string, array<string, mixed>>  $registrations
 * @param  array<string, array<string, mixed>>  $providers
 */
function mapper(array $registrations, array $providers = []): OAuth2ClientPropertiesMapper
{
    $config = new Config(new Repository(['firefly' => ['security' => ['oauth2' => ['client' => ['registration' => $registrations, 'provider' => $providers]]]]]));
    $settings = new OAuth2ClientSettings;

    return new OAuth2ClientPropertiesMapper(OAuth2ClientProperties::fromConfig($config), new OidcDiscovery(app(), new CacheRepository(new ArrayStore), $settings), $settings);
}

it('builds a Google registration from the preset and two credentials, without a single request', function () {
    Http::fake();
    $mapper = mapper(['google' => ['client_id' => 'g-id', 'client_secret' => 'g-secret']]);
    $mapper->validate();

    $google = $mapper->registration('google');

    expect($google->clientId)->toBe('g-id')
        ->and($google->clientAuthenticationMethod)->toBe(ClientAuthenticationMethod::ClientSecretBasic)
        ->and($google->authorizationGrantType)->toBe(AuthorizationGrantType::AuthorizationCode)
        ->and($google->scopes)->toBe(['openid', 'profile', 'email'])
        ->and($google->clientName)->toBe('Google')
        ->and($google->redirectUri)->toBe('{baseUrl}/login/oauth2/code/{registrationId}')
        ->and($google->pkce)->toBeTrue()
        ->and($google->providerDetails->authorizationUri)->toBe('https://accounts.google.com/o/oauth2/v2/auth')
        ->and($google->providerDetails->jwkSetUri)->toBe('https://www.googleapis.com/oauth2/v3/certs')
        ->and($google->providerDetails->issuerUri)->toBe('https://accounts.google.com')
        ->and($google->providerDetails->userNameAttribute)->toBe('sub')
        ->and($google->providerDetails->endSessionUri)->toBeNull()
        ->and($mapper->registration('google'))->toBe($google);
    Http::assertNothingSent();
});

it('resolves a per-tenant preset through discovery, letting an explicit provider key win over the discovered one', function () {
    Http::fake(['https://sso.example.com/realms/corp/.well-known/openid-configuration' => Http::response(mapperDiscoveryDocument('https://sso.example.com/realms/corp'))]);
    $mapper = mapper(
        ['corp' => ['provider' => 'keycloak', 'client_id' => 'portal', 'client_secret' => 'kc-secret', 'client_name' => 'Corporate SSO', 'scope' => 'openid profile']],
        ['keycloak' => ['issuer_uri' => 'https://sso.example.com/realms/corp', 'user_info_uri' => 'https://sso.example.com/custom/userinfo']],
    );
    $mapper->validate();
    Http::assertNothingSent();

    $corp = $mapper->registration('corp');

    expect($corp->clientName)->toBe('Corporate SSO')
        ->and($corp->scopes)->toBe(['openid', 'profile'])
        ->and($corp->providerDetails->authorizationUri)->toBe('https://sso.example.com/realms/corp/protocol/openid-connect/auth')
        ->and($corp->providerDetails->tokenUri)->toBe('https://sso.example.com/realms/corp/protocol/openid-connect/token')
        ->and($corp->providerDetails->jwkSetUri)->toBe('https://sso.example.com/realms/corp/protocol/openid-connect/certs')
        ->and($corp->providerDetails->userInfoUri)->toBe('https://sso.example.com/custom/userinfo')
        ->and($corp->providerDetails->endSessionUri)->toBe('https://sso.example.com/realms/corp/protocol/openid-connect/logout')
        ->and($corp->providerDetails->issuerUri)->toBe('https://sso.example.com/realms/corp');
    Http::assertSentCount(1);
});

it('defaults the method to none for a secret-less client and forces PKCE on it, and to client_secret_post when asked', function () {
    $mapper = mapper([
        'spa' => ['provider' => 'okta', 'client_id' => 'spa', 'pkce' => false],
        'post' => ['provider' => 'okta', 'client_id' => 'p', 'client_secret' => 's', 'client_authentication_method' => 'client_secret_post'],
        'job' => ['provider' => 'okta', 'client_id' => 'j', 'client_secret' => 's', 'authorization_grant_type' => 'client_credentials', 'scope' => ['orders:read']],
    ], ['okta' => ['issuer_uri' => 'https://dev.okta.com/oauth2/default', 'authorization_uri' => 'https://dev.okta.com/a', 'token_uri' => 'https://dev.okta.com/t', 'jwk_set_uri' => 'https://dev.okta.com/j', 'user_info_uri' => 'https://dev.okta.com/u']]);
    $mapper->validate();

    expect($mapper->registration('spa')->clientAuthenticationMethod)->toBe(ClientAuthenticationMethod::None)
        ->and($mapper->registration('spa')->usesPkce())->toBeTrue()
        ->and($mapper->registration('post')->clientAuthenticationMethod)->toBe(ClientAuthenticationMethod::ClientSecretPost)
        ->and($mapper->registration('job')->authorizationGrantType)->toBe(AuthorizationGrantType::ClientCredentials)
        ->and($mapper->registration('job')->scopes)->toBe(['orders:read'])
        ->and($mapper->registrationIds())->toBe(['spa', 'post', 'job'])
        ->and($mapper->has('job'))->toBeTrue()
        ->and($mapper->has('nope'))->toBeFalse();
});

/**
 * Asserts that validate() — the static, network-free phase — refuses the configuration with a message naming the key.
 *
 * @param  array<string, array<string, mixed>>  $registrations
 * @param  array<string, array<string, mixed>>  $providers
 */
function mapperRefuses(array $registrations, array $providers, string $message): void
{
    expect(fn () => mapper($registrations, $providers)->validate())->toThrow(ConfigurationException::class, $message);
}

it('refuses, at validate(), every registration that could not work — naming the key', function () {
    $refused = mapperRefuses(...);

    $refused(['nope' => ['client_id' => 'x']], [], 'neither a preset');
    $refused(['github' => []], [], 'client_id is required');
    $refused(['github' => ['client_id' => 'x']], [], 'client_secret is required');
    $refused(['github' => ['client_id' => 'x', 'client_secret' => 's', 'authorization_grant_type' => 'implicit']], [], 'authorization_grant_type');
    $refused(['github' => ['client_id' => 'x', 'client_secret' => 's', 'client_authentication_method' => 'mtls']], [], 'client_authentication_method');
    $refused(['github' => ['client_id' => 'x', 'client_secret' => 's', 'client-name' => 'GH']], [], 'unknown setting [client-name]');
    $refused(['keycloak' => ['client_id' => 'x', 'client_secret' => 's']], [], 'issuer_uri is required');
    $refused(['github' => ['client_id' => 'x', 'client_secret' => 's', 'scope' => [42]]], [], 'scope');
    $refused(['own' => ['client_id' => 'x', 'client_secret' => 's', 'scope' => ['openid']]], ['own' => ['authorization_uri' => 'https://a', 'token_uri' => 'https://t']], 'jwk_set_uri is required');
    $refused(['own' => ['client_id' => 'x', 'client_secret' => 's', 'scope' => ['read']]], ['own' => ['authorization_uri' => 'https://a', 'token_uri' => 'https://t']], 'user_info_uri is required');
    $refused(['own' => ['client_id' => 'x', 'client_secret' => 's']], ['own' => ['token_uri' => 'https://t']], 'authorization_uri is required');
    $refused(['own' => ['client_id' => 'x', 'client_secret' => 's']], ['own' => ['authorization_uri' => 'https://a', 'token_uri' => 'https://t', 'jwk-set-uri' => 'x']], 'unknown setting [jwk-set-uri]');
});

it('serves the repository port over the mapper', function () {
    $repository = new PropertiesClientRegistrationRepository(mapper(['github' => ['client_id' => 'x', 'client_secret' => 's']]));

    expect($repository->registrationIds())->toBe(['github'])
        ->and($repository->findByRegistrationId('github')?->providerDetails->userNameAttribute)->toBe('id')
        ->and($repository->findByRegistrationId('nope'))->toBeNull()
        ->and(count($repository->all()))->toBe(1);
});
