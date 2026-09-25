<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2;

use Firebase\JWT\JWK;
use Firebase\JWT\Key;

/** A fixed key set — the test seam (no network) and the parsed form RemoteJwksProvider caches. */
final class InMemoryJwksProvider implements JwksProvider
{
    /**
     * @param  array<string,Key>  $keys
     */
    public function __construct(private readonly array $keys) {}

    /**
     * @param  array<string,mixed>  $jwks  a decoded JWKS document ({"keys": [...]})
     */
    public static function fromJwks(array $jwks): self
    {
        /*
         * RS256 AS THE DEFAULT ALGORITHM, BECAUSE THE BIGGEST ISSUER IN THE WORLD OMITS `alg`.
         *
         * php-jwt refuses a key without one — `JWK must contain an "alg" parameter` — and Microsoft
         * Entra's tenant JWKS carries none. Measured against a real tenant on 2026-09-24: six keys,
         * zero with `alg`. So `parseKeySet($jwks)` threw on every request and the resource server
         * could not validate a single token from the identity provider most applications use.
         *
         * The parameter exists for exactly this: php-jwt applies the default ONLY to a key that does
         * not state its own, so an issuer that does state one still wins. RS256 is the right default
         * — it is what every `kty: RSA` key in a public JWKS is in practice, and a key of another
         * type is unaffected because the default is consulted per key.
         */
        /** @var array<string,Key> $keys */
        $keys = JWK::parseKeySet($jwks, 'RS256');

        return new self($keys);
    }

    public function keys(): array
    {
        return $this->keys;
    }
}
