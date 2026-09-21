<?php

declare(strict_types=1);

use Firefly\Kernel\Exception\Framework\ConfigurationException;
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

/**
 * A row as a migration or a hand edit could leave it: every column a valid client would write, with the given
 * columns overridden. The defaults describe a confidential authorization_code client with one absolute redirect.
 *
 * @param  array<string,mixed>  $overrides
 * @return array<string,mixed>
 */
function oauth2ClientRow(string $id, array $overrides = []): array
{
    return array_merge([
        'id' => $id,
        'client_id' => $id.'-id',
        'client_id_issued_at' => null,
        'client_secret' => '{noop}s',
        'client_secret_expires_at' => null,
        'client_name' => $id,
        'client_authentication_methods' => 'client_secret_basic',
        'authorization_grant_types' => 'authorization_code refresh_token',
        'redirect_uris' => 'https://a.test/cb',
        'post_logout_redirect_uris' => '',
        'scopes' => 'openid',
        'client_settings' => '{}',
        'token_settings' => '{}',
    ], $overrides);
}

it('refuses a row the config map would refuse at boot, naming the client, from every read path', function (array $overrides, string $needle) {
    /** @var array<string,mixed> $overrides */
    $repository = new EloquentRegisteredClientRepository(new RegisteredClientModelRepository, new AuthorizationServerSettings);
    DB::table(OAuth2ServerSchema::CLIENTS)->insert(oauth2ClientRow('edited', $overrides));

    expect(fn () => $repository->findById('edited'))->toThrow(ConfigurationException::class, 'Client [edited]')
        ->and(fn () => $repository->findById('edited'))->toThrow(ConfigurationException::class, $needle)
        ->and(fn () => $repository->findByClientId('edited-id'))->toThrow(ConfigurationException::class, $needle)
        ->and(fn () => $repository->all())->toThrow(ConfigurationException::class, $needle);
})->with([
    'unknown method' => [['client_authentication_methods' => 'tls'], 'client_authentication_methods'],
    'unknown grant' => [['authorization_grant_types' => 'password'], 'authorization_grant_types'],
    'confidential without secret' => [['client_secret' => null], 'client_secret'],
    'plain-text secret' => [['client_secret' => 'plain'], '{id}'],
    'public with a secret' => [['client_authentication_methods' => 'none'], 'none'],
    'code without redirect uris' => [['redirect_uris' => ''], 'redirect_uris'],
    'relative redirect uri' => [['redirect_uris' => '/cb'], 'absolute'],
    'redirect uri with a fragment' => [['redirect_uris' => 'https://a.test/cb#x'], 'fragment'],
    'relative post-logout uri' => [['post_logout_redirect_uris' => '/bye'], 'absolute'],
    'private_key_jwt without jwk_set' => [['client_authentication_methods' => 'private_key_jwt', 'client_secret' => null], 'jwk_set'],
    'bad token format' => [['token_settings' => '{"access_token_format":"jwt"}'], 'access_token_format'],
]);

it('leaves a valid row beside a broken one readable by id, and refuses the listing that would include both', function () {
    $repository = new EloquentRegisteredClientRepository(new RegisteredClientModelRepository, new AuthorizationServerSettings);
    DB::table(OAuth2ServerSchema::CLIENTS)->insert(oauth2ClientRow('good'));
    DB::table(OAuth2ServerSchema::CLIENTS)->insert(oauth2ClientRow('bad', ['redirect_uris' => '/cb']));

    expect($repository->findById('good')?->clientId)->toBe('good-id')
        ->and(fn () => $repository->all())->toThrow(ConfigurationException::class, 'Client [bad]');
});
