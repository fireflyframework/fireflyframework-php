<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2;

use Firebase\JWT\Key;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Http;

/**
 * Fetches a JWKS document from a configured URI and caches the PARSED key set for a TTL, so verification never
 * makes a live request per token. Kept behind the JwksProvider port precisely so tests inject
 * InMemoryJwksProvider and never touch the network (spec risk #3). The fetch is memoised in the app cache under
 * a per-URI key.
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
