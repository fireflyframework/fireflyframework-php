<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Client\Oidc;

use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Security\OAuth2\Client\OAuth2ClientSettings;
use Firefly\Security\OAuth2\Client\Registration\ClientRegistration;
use Firefly\Security\OAuth2\RemoteJwksProvider;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * One decoder per JWKS URI (Spring's OidcIdTokenDecoderFactory), each over firefly/security's
 * RemoteJwksProvider — the same bounded, cached fetch the resource server uses, under `jwk_set.cache_ttl` and
 * the package's two timeouts — memoised for the life of the process. The mapper guarantees an `openid`
 * registration has a jwk_set_uri; the refusal here is the honest answer for a registration built by hand.
 */
final class OidcIdTokenDecoderFactory
{
    /** @var array<string, OidcIdTokenDecoder> */
    private array $decoders = [];

    public function __construct(
        private readonly Cache $cache,
        private readonly OAuth2ClientSettings $settings,
    ) {}

    public function createDecoder(ClientRegistration $registration): OidcIdTokenDecoder
    {
        $uri = $registration->providerDetails->jwkSetUri;
        if ($uri === null || $uri === '') {
            throw new ConfigurationException("[{$registration->registrationId}] requests openid but its provider has no jwk_set_uri to verify id tokens with.");
        }

        return $this->decoders[$uri] ??= new OidcIdTokenDecoder(
            new RemoteJwksProvider($uri, $this->cache, $this->settings->jwkSetCacheTtlSeconds, $this->settings->connectTimeoutSeconds, $this->settings->timeoutSeconds),
            $this->settings->clockSkewSeconds,
        );
    }
}
