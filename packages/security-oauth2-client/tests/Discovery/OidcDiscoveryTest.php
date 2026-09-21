<?php

declare(strict_types=1);

use Firefly\Security\OAuth2\Client\Discovery\OidcDiscovery;
use Firefly\Security\OAuth2\Client\Discovery\ProviderDiscoveryException;
use Firefly\Security\OAuth2\Client\OAuth2ClientSettings;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;

uses(TestCase::class);

/** @return array<string, mixed> */
function discoveryDocument(string $issuer = 'https://idp.example.com/realms/corp'): array
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

function discovery(CacheRepository $cache): OidcDiscovery
{
    return new OidcDiscovery(app(), $cache, new OAuth2ClientSettings(discoveryCacheTtlSeconds: 3600));
}

it('fetches the well-known document once and answers the second call, even from another instance, from the cache', function () {
    Http::preventStrayRequests();
    Http::fake(['https://idp.example.com/realms/corp/.well-known/openid-configuration' => Http::response(discoveryDocument())]);
    $cache = new CacheRepository(new ArrayStore);

    $metadata = discovery($cache)->metadata('https://idp.example.com/realms/corp/');

    expect($metadata->issuer)->toBe('https://idp.example.com/realms/corp')
        ->and($metadata->authorizationEndpoint)->toBe('https://idp.example.com/realms/corp/protocol/openid-connect/auth')
        ->and($metadata->tokenEndpoint)->toBe('https://idp.example.com/realms/corp/protocol/openid-connect/token')
        ->and($metadata->jwksUri)->toBe('https://idp.example.com/realms/corp/protocol/openid-connect/certs')
        ->and($metadata->userInfoEndpoint)->toBe('https://idp.example.com/realms/corp/protocol/openid-connect/userinfo')
        ->and($metadata->endSessionEndpoint)->toBe('https://idp.example.com/realms/corp/protocol/openid-connect/logout')
        ->and($metadata->document['issuer'])->toBe('https://idp.example.com/realms/corp');

    expect(discovery($cache)->metadata('https://idp.example.com/realms/corp/')->tokenEndpoint)->toBe($metadata->tokenEndpoint);
    Http::assertSentCount(1);
});

it('refuses a document whose issuer differs, one without the two endpoints, one that is not JSON, and a fetch that fails — caching none of them', function () {
    $cache = new CacheRepository(new ArrayStore);
    Http::preventStrayRequests();
    Http::fake([
        'https://a.example.com/.well-known/openid-configuration' => Http::response(discoveryDocument('https://evil.example.com')),
        'https://b.example.com/.well-known/openid-configuration' => Http::response(['issuer' => 'https://b.example.com']),
        'https://c.example.com/.well-known/openid-configuration' => Http::response('<html>', 200, ['Content-Type' => 'text/html']),
        'https://d.example.com/*' => Http::response('', 503),
    ]);

    expect(fn () => discovery($cache)->metadata('https://a.example.com'))->toThrow(ProviderDiscoveryException::class, 'issuer differs')
        ->and(fn () => discovery($cache)->metadata('https://b.example.com'))->toThrow(ProviderDiscoveryException::class, 'authorization_endpoint')
        ->and(fn () => discovery($cache)->metadata('https://c.example.com'))->toThrow(ProviderDiscoveryException::class, 'not a JSON object')
        ->and(fn () => discovery($cache)->metadata('https://d.example.com'))->toThrow(ProviderDiscoveryException::class, 'd.example.com');

    // A second attempt fetches again: a failure never leaves a poisoned entry behind.
    expect(fn () => discovery($cache)->metadata('https://d.example.com'))->toThrow(ProviderDiscoveryException::class);
    Http::assertSentCount(5);

    // The message names the HOST, never the URI: a tenant hint or a key in the issuer's query stays out of it.
    // A fetch that failed is TRANSIENT — a retry may well succeed — and carries its cause.
    $caught = null;
    try {
        discovery($cache)->metadata('https://d.example.com/tenant?secret=1');
    } catch (ProviderDiscoveryException $e) {
        $caught = $e;
    }
    expect($caught)->toBeInstanceOf(ProviderDiscoveryException::class)
        ->and($caught?->getMessage())->toContain('d.example.com')->not->toContain('secret=1')
        ->and($caught?->httpStatus())->toBe(503)
        ->and($caught?->errorCode())->toBe('OIDC_DISCOVERY_UNAVAILABLE')
        ->and($caught?->transient)->toBeTrue()
        ->and($caught?->getPrevious())->not->toBeNull();

    // A document that was fetched but cannot be used is NOT transient: no retry changes what the provider publishes.
    $invalid = null;
    try {
        discovery($cache)->metadata('https://a.example.com');
    } catch (ProviderDiscoveryException $e) {
        $invalid = $e;
    }
    expect($invalid?->transient)->toBeFalse()
        ->and($invalid?->httpStatus())->toBe(503)
        ->and($invalid?->errorCode())->toBe('OIDC_DISCOVERY_UNAVAILABLE');
});
