<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2;

use Firebase\JWT\Key;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Fetches a JWKS document from a configured URI and caches the RAW JWKS JSON (keyed by URI, under the configured
 * TTL) so verification never makes a live request per token — the parsed key set is NOT what's cached: `Key`/
 * OpenSSL objects don't serialize cleanly through a cache driver, so each `keys()` call re-parses the cached raw
 * JSON via InMemoryJwksProvider::fromJwks(), which is cheap relative to the network round-trip it replaces. Kept
 * behind the JwksProvider port precisely so tests inject InMemoryJwksProvider and never touch the network (spec
 * risk #3).
 *
 * THE FETCH IS BOUNDED, AND ITS FAILURE IS TYPED. It used to run with Laravel's default client timeout — thirty
 * seconds, the same as PHP's execution limit — so a slow or absent issuer produced a FATAL ERROR ("Maximum
 * execution time of 30 seconds exceeded") rather than an exception, and on a single-process dev server whose
 * jwks_uri pointed back at itself (see LocalJwksProvider) the nested fetch deadlocked the pool. Five seconds
 * to connect and five to answer, both configurable; and every failure — a 5xx, a refused connection, a
 * timeout, a body that is not JSON — is a JwksUnavailableException, which the resource-server filter lets
 * through as the 503 it is. A failed fetch caches nothing: `remember()` only stores after the callback
 * returns, so the next call retries the issuer.
 */
final class RemoteJwksProvider implements JwksProvider
{
    public const int DEFAULT_CONNECT_TIMEOUT_SECONDS = 5;

    public const int DEFAULT_TIMEOUT_SECONDS = 5;

    public function __construct(
        private readonly string $jwksUri,
        private readonly Cache $cache,
        private readonly int $cacheTtlSeconds = 3600,
        private readonly int $connectTimeoutSeconds = self::DEFAULT_CONNECT_TIMEOUT_SECONDS,
        private readonly int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
    ) {}

    /**
     * @return array<string,Key>
     */
    public function keys(): array
    {
        /** @var array<string,mixed> $jwks */
        $jwks = $this->cache->remember(
            'firefly.security.jwks.'.sha1($this->jwksUri),
            $this->cacheTtlSeconds,
            function (): array {
                try {
                    $json = Http::acceptJson()
                        ->connectTimeout($this->connectTimeoutSeconds)
                        ->timeout($this->timeoutSeconds)
                        ->get($this->jwksUri)
                        ->throw()
                        ->json();
                } catch (Throwable $e) {
                    throw JwksUnavailableException::at($this->jwksUri, $e);
                }

                if (! is_array($json) || ! isset($json['keys'])) {
                    throw JwksUnavailableException::at($this->jwksUri, new \UnexpectedValueException('The JWKS document is not a JSON object with a "keys" member.'));
                }

                /** @var array<string,mixed> $json */
                return $json;
            },
        );

        return InMemoryJwksProvider::fromJwks($jwks)->keys();
    }
}
