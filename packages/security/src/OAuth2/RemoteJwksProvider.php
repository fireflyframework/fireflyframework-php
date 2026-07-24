<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2;

use Firebase\JWT\Key;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Http;

/**
 * Fetches a JWKS document from a configured URI and caches the RAW JWKS JSON (keyed by URI, under the configured
 * TTL) so verification never makes a live request per token — the parsed key set is NOT what's cached: `Key`/
 * OpenSSL objects don't serialize cleanly through a cache driver, so each `keys()` call re-parses the cached raw
 * JSON via InMemoryJwksProvider::fromJwks(), which is cheap relative to the network round-trip it replaces. Kept
 * behind the JwksProvider port precisely so tests inject InMemoryJwksProvider and never touch the network (spec
 * risk #3).
 */
final class RemoteJwksProvider implements JwksProvider
{
    public function __construct(
        private readonly string $jwksUri,
        private readonly Cache $cache,
        private readonly int $cacheTtlSeconds = 3600,
    ) {}

    public function keys(): array
    {
        /** @var array<string,mixed> $jwks */
        $jwks = $this->cache->remember(
            'firefly.security.jwks.'.sha1($this->jwksUri),
            $this->cacheTtlSeconds,
            function (): array {
                /** @var array<string,mixed> $json */
                $json = Http::acceptJson()->get($this->jwksUri)->throw()->json();

                return $json;
            },
        );

        return InMemoryJwksProvider::fromJwks($jwks)->keys();
    }
}
