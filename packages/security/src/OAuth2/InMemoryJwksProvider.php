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
        /** @var array<string,Key> $keys */
        $keys = JWK::parseKeySet($jwks);

        return new self($keys);
    }

    public function keys(): array
    {
        return $this->keys;
    }
}
