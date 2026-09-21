<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Jose;

use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;

/**
 * Signs the server's JWTs (access tokens, id tokens) with the current key — `kid` in the header so a resource
 * server picks the right key from the JWKS — and verifies the server's OWN tokens (userinfo bearers, logout
 * `id_token_hint`s) against every published key. php-jwt's exceptions propagate unchanged; the endpoints map
 * them to `invalid_token`. decodeIgnoringExpiry() is for the logout hint: an id token a browser presents to end
 * its session is routinely past its `exp`, and the specification says to accept it.
 */
final class JwtGenerator
{
    public function __construct(private readonly JwtSigningKeys $keys) {}

    public function keys(): JwtSigningKeys
    {
        return $this->keys;
    }

    public function algorithm(): string
    {
        return $this->keys->current()->algorithm;
    }

    /**
     * @param  array<string,mixed>  $claims
     */
    public function encode(array $claims): string
    {
        $key = $this->keys->current();

        return JWT::encode($claims, $key->key, $key->algorithm, $key->kid);
    }

    /**
     * @return array<string,mixed>
     */
    public function decode(string $jwt): array
    {
        /** @var array<string,mixed> $claims */
        $claims = (array) JWT::decode($jwt, $this->keys->verificationKeys());

        return $claims;
    }

    /**
     * @return array<string,mixed>
     */
    public function decodeIgnoringExpiry(string $jwt): array
    {
        try {
            return $this->decode($jwt);
        } catch (ExpiredException $e) {
            /** @var array<string,mixed> $claims */
            $claims = (array) $e->getPayload();

            return $claims;
        }
    }
}
