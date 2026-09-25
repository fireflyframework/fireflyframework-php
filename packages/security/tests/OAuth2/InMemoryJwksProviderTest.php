<?php

declare(strict_types=1);

use Firefly\Security\OAuth2\InMemoryJwksProvider;

/**
 * THE BIGGEST ISSUER IN THE WORLD OMITS `alg`, AND php-jwt REFUSES A KEY WITHOUT ONE.
 *
 * Microsoft Entra's tenant JWKS carries no `alg` on any key — measured against a live tenant on
 * 2026-09-24: six keys, zero with the parameter. `JWK::parseKeySet($jwks)` answers
 * `JWK must contain an "alg" parameter`, so a resource server built on this provider could not
 * validate a single token from the identity provider most applications use. The fixtures below are
 * that shape.
 */
/**
 * @return array<string, mixed> one JWKS key, `alg` present or absent
 */
function jwksKeyWithout(?string $alg): array
{
    // A real RSA public key's components; only `alg` varies between the cases.
    $key = [
        'kty' => 'RSA',
        'use' => 'sig',
        'kid' => $alg === null ? 'no-alg' : 'with-alg',
        'n' => 'sXchDaQebHnPiGvyDOAT4saGEUetSyo9MKLOoWFsueri23bOdgWp4Dy1Wl'
            .'UzewbgBHod5pcM9H95GQRV3JDXboIRROSBigeC5yjU1hGzHHyXss8UDpre'
            .'cbAYxknTcQkhslANGRUZmdTOQ5qTRsLAt6BTYuyvVRdhS8exSZEy_c4gs_'
            .'7svlJJQ4H9_NxsiIguRBUiuFbzSOnAd8_5FSvxZLeAJgnRD-Cn4bPU0-Fy'
            .'mrLBTLnvNlqNlZNTxGPrxdDKFhr2ZJ0lkTa1o_ZBxJcZjLrZWTcJgLLQKw'
            .'ZPxRXlTSYDT2v6Tvd4rPBGQxOqgqzOXqfGpMkIQhcHGUvQ',
        'e' => 'AQAB',
    ];

    if ($alg !== null) {
        $key['alg'] = $alg;
    }

    return ['keys' => [$key]];
}

it('parses a key set whose keys do not state an algorithm', function (): void {
    $provider = InMemoryJwksProvider::fromJwks(jwksKeyWithout(null));

    expect($provider->keys())->toHaveKey('no-alg');
});

it('lets a key that states its own algorithm keep it', function (): void {
    // The default is consulted PER KEY, so an issuer that publishes `alg` still decides.
    $provider = InMemoryJwksProvider::fromJwks(jwksKeyWithout('RS256'));

    expect($provider->keys())->toHaveKey('with-alg')
        ->and($provider->keys()['with-alg']->getAlgorithm())->toBe('RS256');
});
