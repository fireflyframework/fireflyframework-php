<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Security\OAuth2\Server\Settings\AuthorizationServerSettings;
use Firefly\Security\OAuth2\Server\Settings\OAuth2TokenFormat;
use Illuminate\Config\Repository;
use Illuminate\Http\Request;

/** @param array<string, mixed> $server */
function serverSettings(array $server, string $appUrl = 'https://issuer.test'): AuthorizationServerSettings
{
    return AuthorizationServerSettings::fromConfig(new Config(new Repository([
        'app' => ['url' => $appUrl],
        'firefly' => ['security' => ['oauth2' => ['server' => $server]]],
    ])));
}

it('reads every default: off, the issuer from app.url, Spring\'s endpoint paths, RS256 JWTs, rotation and consent on', function () {
    $settings = serverSettings([]);

    expect($settings->enabled)->toBeFalse()
        ->and($settings->issuer)->toBe('https://issuer.test')
        ->and($settings->authorizationEndpoint)->toBe('/oauth2/authorize')
        ->and($settings->tokenEndpoint)->toBe('/oauth2/token')
        ->and($settings->jwkSetEndpoint)->toBe('/oauth2/jwks')
        ->and($settings->tokenIntrospectionEndpoint)->toBe('/oauth2/introspect')
        ->and($settings->tokenRevocationEndpoint)->toBe('/oauth2/revoke')
        ->and($settings->oidcUserInfoEndpoint)->toBe('/userinfo')
        ->and($settings->oidcLogoutEndpoint)->toBe('/connect/logout')
        ->and($settings->oidcClientRegistrationEndpoint)->toBe('')
        ->and($settings->hasClientRegistration())->toBeFalse()
        ->and($settings->signingKey)->toBe('')
        ->and($settings->keyId)->toBe('')
        ->and($settings->algorithm)->toBe('RS256')
        ->and($settings->previousKeys)->toBe([])
        ->and($settings->accessTokenFormat)->toBe(OAuth2TokenFormat::SelfContained)
        ->and($settings->accessTokenTtl)->toBe(300)
        ->and($settings->refreshTokenTtl)->toBe(3600)
        ->and($settings->reuseRefreshTokens)->toBeFalse()
        ->and($settings->authorizationCodeTtl)->toBe(300)
        ->and($settings->idTokenTtl)->toBe(1800)
        ->and($settings->requirePkce)->toBeTrue()
        ->and($settings->requireProofKeyForPublicClients)->toBeTrue()
        ->and($settings->consentRequired)->toBeTrue()
        ->and($settings->consentView)->toBeNull()
        ->and($settings->clientsDriver)->toBe('memory')
        ->and($settings->authorizationsDriver)->toBe('memory')
        ->and($settings->purgeEnabled)->toBeFalse()
        ->and($settings->purgeCron)->toBe('*/15 * * * *')
        ->and($settings->rateLimitEnabled)->toBeFalse()
        ->and($settings->rateLimitMaxTokens)->toBe(60)
        ->and($settings->rateLimitRefillRate)->toBe(1.0);
});

it('builds endpoint URLs from the issuer and matches an endpoint by path only', function () {
    $settings = serverSettings(['issuer' => 'https://issuer.test/auth/']);

    expect($settings->endpointUrl('/oauth2/token'))->toBe('https://issuer.test/auth/oauth2/token')
        ->and($settings->isEndpoint(Request::create('/oauth2/token?x=1', 'POST'), '/oauth2/token'))->toBeTrue()
        ->and($settings->isEndpoint(Request::create('/oauth2/token/', 'POST'), '/oauth2/token'))->toBeTrue()
        ->and($settings->isEndpoint(Request::create('/oauth2/tokens', 'POST'), '/oauth2/token'))->toBeFalse();
});

it('refuses an unknown algorithm, format or driver, naming the key', function (array $server, string $key) {
    /** @var array<string, mixed> $server */
    expect(fn () => serverSettings($server))->toThrow(ConfigurationException::class, $key);
})->with([
    'algorithm' => [['jwt' => ['algorithm' => 'HS256']], 'firefly.security.oauth2.server.jwt.algorithm'],
    'format' => [['access_token' => ['format' => 'jwt']], 'firefly.security.oauth2.server.access_token.format'],
    'clients driver' => [['clients' => ['driver' => 'redis']], 'firefly.security.oauth2.server.clients.driver'],
    'authorizations driver' => [['authorizations' => ['driver' => 'redis']], 'firefly.security.oauth2.server.authorizations.driver'],
    'ttl' => [['access_token' => ['ttl' => 0]], 'firefly.security.oauth2.server.access_token.ttl'],
    'issuer' => [['issuer' => 'issuer.test'], 'firefly.security.oauth2.server.issuer'],
    'issuer with query' => [['issuer' => 'https://issuer.test/?x=1'], 'firefly.security.oauth2.server.issuer'],
    'endpoint without slash' => [['token_endpoint' => 'oauth2/token'], 'firefly.security.oauth2.server.token_endpoint'],
    'duplicate endpoints' => [['token_endpoint' => '/oauth2/authorize'], 'distinct'],
    'cron' => [['authorizations' => ['purge' => ['cron' => '']]], 'firefly.security.oauth2.server.authorizations.purge.cron'],
    'max_tokens' => [['rate_limit' => ['max_tokens' => 0]], 'firefly.security.oauth2.server.rate_limit.max_tokens'],
    'refill_rate that is not a number' => [['rate_limit' => ['refill_rate' => 'fast']], 'firefly.security.oauth2.server.rate_limit.refill_rate'],
    'refill_rate that is empty' => [['rate_limit' => ['refill_rate' => '']], 'firefly.security.oauth2.server.rate_limit.refill_rate'],
    'refill_rate with a locale comma' => [['rate_limit' => ['refill_rate' => '1,5']], 'firefly.security.oauth2.server.rate_limit.refill_rate'],
    'refill_rate of zero' => [['rate_limit' => ['refill_rate' => 0]], 'firefly.security.oauth2.server.rate_limit.refill_rate'],
    'refill_rate below zero' => [['rate_limit' => ['refill_rate' => '-3']], 'firefly.security.oauth2.server.rate_limit.refill_rate'],
]);

it('refuses a refill rate that is not positive on a hand-built instance too', function () {
    expect(fn () => new AuthorizationServerSettings(rateLimitRefillRate: 0.0))
        ->toThrow(ConfigurationException::class, 'firefly.security.oauth2.server.rate_limit.refill_rate');
});

it('reads a numeric refill rate however the environment spelled it', function (mixed $configured, float $expected) {
    expect(serverSettings(['rate_limit' => ['refill_rate' => $configured]])->rateLimitRefillRate)->toBe($expected);
})->with([
    'float' => [0.5, 0.5],
    'int' => [2, 2.0],
    'string from .env' => ['0.25', 0.25],
]);

it('accepts ES256, reference tokens, the eloquent drivers, a consent view and the registration endpoint', function () {
    $settings = serverSettings([
        'jwt' => ['algorithm' => 'ES256', 'key_id' => 'k1', 'previous_keys' => [['key' => '/tmp/old.pem', 'key_id' => 'k0']]],
        'access_token' => ['format' => 'reference', 'ttl' => 60],
        'consent' => ['required' => false, 'view' => 'auth.consent'],
        'clients' => ['driver' => 'eloquent'],
        'authorizations' => ['driver' => 'eloquent', 'purge' => ['enabled' => true, 'cron' => '0 * * * *']],
        'oidc_client_registration_endpoint' => '/connect/register',
        'rate_limit' => ['enabled' => true, 'max_tokens' => 5, 'refill_rate' => 0.5],
    ]);

    expect($settings->algorithm)->toBe('ES256')
        ->and($settings->keyId)->toBe('k1')
        ->and($settings->previousKeys)->toBe([['key' => '/tmp/old.pem', 'key_id' => 'k0']])
        ->and($settings->accessTokenFormat)->toBe(OAuth2TokenFormat::Reference)
        ->and($settings->consentRequired)->toBeFalse()
        ->and($settings->consentView)->toBe('auth.consent')
        ->and($settings->clientsDriver)->toBe('eloquent')
        ->and($settings->purgeEnabled)->toBeTrue()
        ->and($settings->purgeCron)->toBe('0 * * * *')
        ->and($settings->hasClientRegistration())->toBeTrue()
        ->and($settings->rateLimitMaxTokens)->toBe(5)
        ->and($settings->rateLimitRefillRate)->toBe(0.5);
});
