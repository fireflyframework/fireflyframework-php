<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Client\Discovery;

use Firefly\Security\OAuth2\Client\OAuth2ClientSettings;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Client\Factory as HttpFactory;
use Throwable;

/**
 * Fetches {issuer}/.well-known/openid-configuration and caches the RAW document (keyed by issuer, for
 * `discovery.cache_ttl`), the shape RemoteJwksProvider gives the JWKS: the parsed value object is rebuilt from
 * the cached JSON on every process (cheap), and a document that fails validation is never cached at all —
 * `remember()` stores only what the callback returns, and the callback validates before returning — so a
 * provider that answers garbage is retried on the next call rather than remembered for an hour.
 *
 * THE FETCH IS BOUNDED, AND ITS FAILURE IS TYPED, for exactly the reasons the JWKS fetch is: `http.connect_timeout`
 * and `http.timeout` (5 s each) instead of Laravel's thirty, and every failure — a 5xx, a refused connection, a
 * timeout, a body that is not JSON, an issuer that does not match — is a ProviderDiscoveryException (503).
 *
 * The Http factory is resolved from the container ON USE, never injected: this is an eager singleton built at
 * every boot, including boots that never make a request, and Laravel's Http facade root is what Http::fake()
 * stubs, so resolving the same singleton keeps a test's fake in charge.
 */
final class OidcDiscovery
{
    public const string WELL_KNOWN = '/.well-known/openid-configuration';

    /** @var array<string, OidcProviderMetadata> */
    private array $resolved = [];

    public function __construct(
        private readonly Container $container,
        private readonly Cache $cache,
        private readonly OAuth2ClientSettings $settings,
    ) {}

    public function metadata(string $issuerUri): OidcProviderMetadata
    {
        if (isset($this->resolved[$issuerUri])) {
            return $this->resolved[$issuerUri];
        }

        /** @var array<string, mixed> $document */
        $document = $this->cache->remember(
            'firefly.security.oauth2.client.discovery.'.sha1($issuerUri),
            $this->settings->discoveryCacheTtlSeconds,
            function () use ($issuerUri): array {
                $url = rtrim($issuerUri, '/').self::WELL_KNOWN;
                try {
                    /** @var HttpFactory $http */
                    $http = $this->container->make(HttpFactory::class);
                    $json = $http->acceptJson()
                        ->connectTimeout($this->settings->connectTimeoutSeconds)
                        ->timeout($this->settings->timeoutSeconds)
                        ->get($url)
                        ->throw()
                        ->json();
                } catch (Throwable $e) {
                    throw ProviderDiscoveryException::at($issuerUri, $e);
                }

                if (! is_array($json)) {
                    throw ProviderDiscoveryException::invalid($issuerUri, 'it is not a JSON object.');
                }

                /** @var array<string, mixed> $json */
                OidcProviderMetadata::fromDocument($json, $issuerUri); // validated BEFORE it is cached

                return $json;
            },
        );

        return $this->resolved[$issuerUri] = OidcProviderMetadata::fromDocument($document, $issuerUri);
    }
}
