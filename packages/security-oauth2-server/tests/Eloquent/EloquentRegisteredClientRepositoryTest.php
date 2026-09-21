<?php

declare(strict_types=1);

use Firefly\Security\OAuth2\Server\Client\ClientAuthenticationMethod;
use Firefly\Security\OAuth2\Server\Client\RegisteredClientFactory;
use Firefly\Security\OAuth2\Server\Eloquent\EloquentRegisteredClientRepository;
use Firefly\Security\OAuth2\Server\Eloquent\OAuth2ServerSchema;
use Firefly\Security\OAuth2\Server\Eloquent\RegisteredClientModelRepository;
use Firefly\Security\OAuth2\Server\Settings\AuthorizationServerSettings;
use Firefly\Security\OAuth2\Server\Settings\OAuth2TokenFormat;
use Firefly\Security\OAuth2\Server\Tests\Support\OAuth2ServerSqliteTestCase;
use Illuminate\Support\Facades\DB;

uses(OAuth2ServerSqliteTestCase::class);

it('round-trips a client through the oauth2_registered_clients table, lists and settings included', function () {
    $settings = new AuthorizationServerSettings;
    $repository = new EloquentRegisteredClientRepository(new RegisteredClientModelRepository, $settings);
    $client = RegisteredClientFactory::fromConfig('web-app', [
        'client_id' => 'web-app-id',
        'client_secret' => '{noop}s',
        'client_name' => 'Web',
        'client_authentication_methods' => ['client_secret_basic', 'client_secret_post'],
        'authorization_grant_types' => ['authorization_code', 'refresh_token', 'client_credentials'],
        'redirect_uris' => ['https://a.test/cb', 'https://a.test/cb2'],
        'post_logout_redirect_uris' => ['https://a.test/'],
        'scopes' => ['openid', 'profile'],
        'client_settings' => ['require_pkce' => true, 'require_authorization_consent' => false, 'jwk_set' => ['keys' => [['kty' => 'RSA', 'kid' => 'x', 'n' => 'AQ', 'e' => 'AQAB']]]],
        'token_settings' => ['access_token_ttl' => 120, 'access_token_format' => 'reference'],
    ], $settings);

    $repository->save($client);
    $repository->save($client); // idempotent: the row is replaced, not duplicated

    expect(DB::table(OAuth2ServerSchema::CLIENTS)->count())->toBe(1)
        ->and($repository->all())->toHaveCount(1)
        ->and($repository->findById('nope'))->toBeNull();

    $found = $repository->findByClientId('web-app-id');
    expect($found)->not->toBeNull()
        ->and($found?->id)->toBe('web-app')
        ->and($found?->clientName)->toBe('Web')
        ->and($found?->clientSecret)->toBe('{noop}s')
        ->and($found?->clientAuthenticationMethods)->toBe([ClientAuthenticationMethod::ClientSecretBasic, ClientAuthenticationMethod::ClientSecretPost])
        ->and($found?->redirectUris)->toBe(['https://a.test/cb', 'https://a.test/cb2'])
        ->and($found?->postLogoutRedirectUris)->toBe(['https://a.test/'])
        ->and($found?->scopes)->toBe(['openid', 'profile'])
        ->and($found?->clientSettings->requireProofKey)->toBeTrue()
        ->and($found?->clientSettings->requireAuthorizationConsent)->toBeFalse()
        ->and($found?->clientSettings->jwkSet)->toBe(['keys' => [['kty' => 'RSA', 'kid' => 'x', 'n' => 'AQ', 'e' => 'AQAB']]])
        ->and($found?->tokenSettings->accessTokenTtl)->toBe(120)
        ->and($found?->tokenSettings->accessTokenFormat)->toBe(OAuth2TokenFormat::Reference)
        ->and($found?->tokenSettings->refreshTokenTtl)->toBe(3600)
        ->and($repository->findById('web-app')?->clientId)->toBe('web-app-id');
});
