<?php

declare(strict_types=1);

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firefly\Config\Config;
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

/** @return array{0: OAuth2ResourceServerFilter, 1: string} filter + a signed RS256 token */
function oauthFixture(): array
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

    $token = JWT::encode(['sub' => 'svc-1', 'scope' => 'orders:read orders:write', 'exp' => time() + 3600], $privatePem, 'RS256', 'kid-1');

    $provider = new InMemoryJwksProvider(['kid-1' => new Key($publicPem, 'RS256')]);
    $config = new Config(new Repository(['firefly' => ['security' => ['oauth2' => ['resource_server' => ['enabled' => true]]]]]));

    return [new OAuth2ResourceServerFilter($provider, $config), $token];
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
