<?php

declare(strict_types=1);

use Firefly\Security\OAuth2\RemoteJwksProvider;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;

uses(TestCase::class);

/**
 * Builds a decoded JWKS document ({"keys": [...]}) from a fresh RSA key pair — enough for
 * Firebase\JWT\JWK::parseKeySet() (invoked by InMemoryJwksProvider::fromJwks() inside RemoteJwksProvider) to
 * succeed, without any network access.
 *
 * @return array<string,mixed>
 */
function fakeJwksDocument(string $kid = 'kid-1'): array
{
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    if ($key === false) {
        throw new RuntimeException('Failed to generate an RSA key pair for the test fixture.');
    }
    $details = openssl_pkey_get_details($key);
    $rsa = $details === false ? null : ($details['rsa'] ?? null);
    if (! is_array($rsa) || ! isset($rsa['n'], $rsa['e']) || ! is_string($rsa['n']) || ! is_string($rsa['e'])) {
        throw new RuntimeException('Failed to read RSA modulus/exponent for the test fixture.');
    }

    $base64Url = static fn (string $binary): string => rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');

    return [
        'keys' => [
            [
                'kty' => 'RSA',
                'kid' => $kid,
                'use' => 'sig',
                'alg' => 'RS256',
                'n' => $base64Url($rsa['n']),
                'e' => $base64Url($rsa['e']),
            ],
        ],
    ];
}

/** A fresh, empty, in-process cache — the `array` driver, built directly (no container/facade needed). */
function freshArrayCache(): CacheRepository
{
    return new CacheRepository(new ArrayStore);
}

it('fetches and parses the JWKS once, then serves the SECOND call from the raw-JSON cache (no re-fetch)', function () {
    $jwksUri = 'https://issuer.example.com/.well-known/jwks.json';
    Http::fake([$jwksUri => Http::response(fakeJwksDocument(), 200)]);

    $provider = new RemoteJwksProvider($jwksUri, freshArrayCache(), 3600);

    $keys = $provider->keys();
    expect($keys)->not->toBeEmpty()
        ->and($keys)->toHaveKey('kid-1');

    // Second call: must be served from the cached raw JSON, not a second HTTP round-trip.
    $keysAgain = $provider->keys();
    expect($keysAgain)->toHaveKey('kid-1');

    Http::assertSentCount(1);
});

it('fails closed on a fetch failure and leaves nothing usable cached', function () {
    $jwksUri = 'https://issuer.example.com/.well-known/jwks.json';
    Http::fake([$jwksUri => Http::response('', 500)]);

    $cache = freshArrayCache();
    $provider = new RemoteJwksProvider($jwksUri, $cache, 3600);

    expect(fn () => $provider->keys())->toThrow(RequestException::class);

    // The failed fetch must not poison the cache — remember()'s put() only runs after the callback
    // returns successfully, so a subsequent call (e.g. once the issuer recovers) is free to retry.
    expect($cache->get('firefly.security.jwks.'.sha1($jwksUri)))->toBeNull();

    Http::assertSentCount(1);
});
