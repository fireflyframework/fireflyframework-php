<?php

declare(strict_types=1);

use Firefly\Security\OAuth2\JwksUnavailableException;
use Firefly\Security\OAuth2\RemoteJwksProvider;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
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

    // The failure is typed and a 503, not the HTTP client's own RequestException: the token was never
    // examined, and a caller answered 401 for an outage it did not cause would rotate a perfectly good token.
    try {
        $provider->keys();
        throw new LogicException('not thrown');
    } catch (JwksUnavailableException $e) {
        expect($e->httpStatus())->toBe(503)
            ->and($e->errorCode())->toBe(JwksUnavailableException::CODE)
            // The HOST is named, so an operator knows which upstream is down; the full URI is not, because
            // a query string on a JWKS URI is where a tenant hint or a key would sit.
            ->and($e->getMessage())->toContain('issuer.example.com')
            ->not->toContain('/.well-known/')
            ->and($e->getPrevious())->toBeInstanceOf(RequestException::class);
    }

    // The failed fetch must not poison the cache — remember()'s put() only runs after the callback
    // returns successfully, so a subsequent call (e.g. once the issuer recovers) is free to retry.
    expect($cache->get('firefly.security.jwks.'.sha1($jwksUri)))->toBeNull();

    Http::assertSentCount(1);
});

/*
 * THE TIMEOUT THAT TOOK A DEV STACK DOWN. The fetch ran with Laravel's default client timeout, thirty seconds
 * — the same as PHP's execution limit — inside cache->remember(). With CACHE_STORE=array (a store that lives
 * one request) and a JWKS URI pointing back at the same `php -S` pool, every authenticated request made a
 * nested request that needed a second free worker; when the pool ran out the outer request sat in curl until
 * the engine killed it, and the client saw "Maximum execution time of 30 seconds exceeded" on an ordinary GET.
 * Five seconds to connect and five to answer, both configurable, and a failure that is an exception a 503
 * can be made from rather than a fatal error nothing can be made from.
 */
it('bounds the fetch with connect and read timeouts, five seconds each by default', function () {
    $jwksUri = 'https://issuer.example.com/.well-known/jwks.json';
    $seen = [];
    Http::fake(function (Request $request, array $options) use (&$seen) {
        $seen = $options;

        return Http::response(fakeJwksDocument(), 200);
    });

    (new RemoteJwksProvider($jwksUri, freshArrayCache(), 3600))->keys();

    expect($seen['connect_timeout'])->toEqual(5)
        ->and($seen['timeout'])->toEqual(5);
});

it('honours explicit timeouts', function () {
    $jwksUri = 'https://issuer.example.com/.well-known/jwks.json';
    $seen = [];
    Http::fake(function (Request $request, array $options) use (&$seen) {
        $seen = $options;

        return Http::response(fakeJwksDocument(), 200);
    });

    (new RemoteJwksProvider($jwksUri, freshArrayCache(), 3600, connectTimeoutSeconds: 2, timeoutSeconds: 3))->keys();

    expect($seen['connect_timeout'])->toEqual(2)
        ->and($seen['timeout'])->toEqual(3);
});

it('reports a timed-out or refused connection as JwksUnavailableException too', function () {
    $jwksUri = 'https://issuer.example.com/.well-known/jwks.json';
    Http::fake(static fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

    expect(fn () => (new RemoteJwksProvider($jwksUri, freshArrayCache(), 3600))->keys())
        ->toThrow(JwksUnavailableException::class);
});

it('reports a JWKS document that is not JSON as JwksUnavailableException', function () {
    $jwksUri = 'https://issuer.example.com/.well-known/jwks.json';
    Http::fake([$jwksUri => Http::response('<html>maintenance</html>', 200, ['Content-Type' => 'text/html'])]);

    expect(fn () => (new RemoteJwksProvider($jwksUri, freshArrayCache(), 3600))->keys())
        ->toThrow(JwksUnavailableException::class);
});
