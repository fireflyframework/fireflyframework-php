<?php

declare(strict_types=1);

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firefly\Config\Config;
use Firefly\Kernel\Exception\Security\InvalidTokenException;
use Firefly\Security\Core\Authentication;
use Firefly\Security\Core\SecurityContext;
use Firefly\Security\Core\SecurityContextHolder;
use Firefly\Security\OAuth2\InMemoryJwksProvider;
use Firefly\Security\OAuth2\OAuth2ResourceServerFilter;
use Illuminate\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Orchestra\Testbench\TestCase;

uses(TestCase::class);

afterEach(fn () => SecurityContextHolder::clearContext());

/**
 * @param  array<string,mixed>  $extraClaims  merged into (and overriding) the default token claims
 * @param  array<string,mixed>  $extraConfig  merged into (and overriding) the default resource_server config
 * @return array{0: OAuth2ResourceServerFilter, 1: string} filter + a signed RS256 token
 */
function oauthFixture(array $extraClaims = [], array $extraConfig = []): array
{
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    if ($key === false) {
        throw new RuntimeException('Failed to generate an RSA key pair for the test fixture.');
    }
    openssl_pkey_export($key, $privatePem);
    /** @var string $privatePem */
    $details = openssl_pkey_get_details($key);
    if ($details === false) {
        throw new RuntimeException('Failed to read RSA key details for the test fixture.');
    }
    /** @var string $publicPem */
    $publicPem = $details['key'];

    $claims = array_merge(['sub' => 'svc-1', 'scope' => 'orders:read orders:write', 'exp' => time() + 3600], $extraClaims);
    $token = JWT::encode($claims, $privatePem, 'RS256', 'kid-1');

    $provider = new InMemoryJwksProvider(['kid-1' => new Key($publicPem, 'RS256')]);
    $config = new Config(new Repository([
        'firefly' => ['security' => ['oauth2' => ['resource_server' => array_merge(['enabled' => true], $extraConfig)]]],
    ]));

    return [new OAuth2ResourceServerFilter($provider, $config), $token];
}

/** Runs the filter over a Bearer request for $token and returns the Authentication seen downstream (or null). */
function authenticateVia(OAuth2ResourceServerFilter $filter, string $token): ?Authentication
{
    $request = Request::create('/api/x', 'GET');
    $request->headers->set('Authorization', 'Bearer '.$token);

    $seen = null;
    $filter->handle($request, function () use (&$seen) {
        $seen = SecurityContextHolder::getAuthentication();

        return new Response('ok');
    });

    return $seen;
}

it('authenticates a JWKS-validated bearer token and maps scopes to SCOPE_ authorities', function () {
    [$filter, $token] = oauthFixture();
    $request = Request::create('/api/x', 'GET');
    $request->headers->set('Authorization', 'Bearer '.$token);

    $seen = null;
    $filter->handle($request, function () use (&$seen) {
        $seen = SecurityContextHolder::getAuthentication();

        return new Response('ok');
    });

    expect($seen?->getName())->toBe('svc-1')
        ->and($seen?->authorityStrings())->toBe(['SCOPE_orders:read', 'SCOPE_orders:write'])
        ->and(SecurityContextHolder::getAuthentication())->toBeNull();
});

it('does not overwrite an already-authenticated context', function () {
    [$filter, $token] = oauthFixture();
    SecurityContextHolder::setContext(new SecurityContext(
        Authentication::authenticated('local', 'local', [])
    ));

    $request = Request::create('/api/x', 'GET');
    $request->headers->set('Authorization', 'Bearer '.$token);

    $seen = null;
    $filter->handle($request, function () use (&$seen) {
        $seen = SecurityContextHolder::getAuthentication();

        return new Response('ok');
    });

    expect($seen?->getName())->toBe('local');
});

it('accepts a token whose string aud matches the configured audience', function () {
    [$filter, $token] = oauthFixture(['aud' => 'orders-api'], ['audience' => 'orders-api']);

    $seen = authenticateVia($filter, $token);

    expect($seen?->getName())->toBe('svc-1');
});

it('accepts a token whose array-of-strings aud contains the configured audience', function () {
    [$filter, $token] = oauthFixture(['aud' => ['billing-api', 'orders-api']], ['audience' => 'orders-api']);

    $seen = authenticateVia($filter, $token);

    expect($seen?->getName())->toBe('svc-1');
});

it('rejects a token whose aud does not match the configured audience', function () {
    [$filter, $token] = oauthFixture(['aud' => 'billing-api'], ['audience' => 'orders-api']);

    expect(fn () => authenticateVia($filter, $token))->toThrow(InvalidTokenException::class);
    expect(SecurityContextHolder::getAuthentication())->toBeNull();
});

it('rejects a token with no aud claim at all when an audience is configured', function () {
    [$filter, $token] = oauthFixture([], ['audience' => 'orders-api']);

    expect(fn () => authenticateVia($filter, $token))->toThrow(InvalidTokenException::class);
    expect(SecurityContextHolder::getAuthentication())->toBeNull();
});

it('accepts a token whose iss matches the configured issuer', function () {
    [$filter, $token] = oauthFixture(['iss' => 'https://issuer.example.com'], ['issuer' => 'https://issuer.example.com']);

    $seen = authenticateVia($filter, $token);

    expect($seen?->getName())->toBe('svc-1');
});

it('rejects a token whose iss does not match the configured issuer', function () {
    [$filter, $token] = oauthFixture(['iss' => 'https://impostor.example.com'], ['issuer' => 'https://issuer.example.com']);

    expect(fn () => authenticateVia($filter, $token))->toThrow(InvalidTokenException::class);
    expect(SecurityContextHolder::getAuthentication())->toBeNull();
});

it('rejects a token with no iss claim at all when an issuer is configured', function () {
    [$filter, $token] = oauthFixture([], ['issuer' => 'https://issuer.example.com']);

    expect(fn () => authenticateVia($filter, $token))->toThrow(InvalidTokenException::class);
    expect(SecurityContextHolder::getAuthentication())->toBeNull();
});

it('accepts a signature+exp-valid token when neither issuer nor audience is configured (backward compatible)', function () {
    // Token carries iss/aud claims, but the resource server is NOT configured to check either — they are
    // simply ignored, exactly as before this fix.
    [$filter, $token] = oauthFixture(['iss' => 'https://anyone.example.com', 'aud' => 'anything']);

    $seen = authenticateVia($filter, $token);

    expect($seen?->getName())->toBe('svc-1');
});
