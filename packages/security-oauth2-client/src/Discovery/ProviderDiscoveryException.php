<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Client\Discovery;

use Firefly\Kernel\Exception\Infrastructure\ServiceUnavailableException;
use Throwable;

/**
 * The provider's OpenID Connect discovery document could not be fetched or cannot be used, so a registration
 * that relies on it cannot be resolved right now.
 *
 * A 503 like JwksUnavailableException, and for the same reason: the caller's request was never examined, an
 * upstream the application depends on did not answer. The HOST is named so an operator knows which provider
 * is down; the full URI is not, because a tenant hint or a key can live in its query. The cause rides along as
 * `previous` for the log. Raised at boot (with `discovery.eager`) it fails the boot; raised on first use it is
 * the 503 the request gets.
 */
final class ProviderDiscoveryException extends ServiceUnavailableException
{
    public const string CODE = 'OIDC_DISCOVERY_UNAVAILABLE';

    public static function at(string $issuerUri, Throwable $cause): self
    {
        return new self(
            sprintf('The OpenID Connect discovery document of %s could not be fetched, so the provider cannot be used right now. Try again in a moment.', self::host($issuerUri)),
            self::CODE,
            $cause,
        );
    }

    public static function invalid(string $issuerUri, string $reason): self
    {
        return new self(
            sprintf('The OpenID Connect discovery document of %s cannot be used: %s', self::host($issuerUri), $reason),
            self::CODE,
        );
    }

    private static function host(string $issuerUri): string
    {
        $host = parse_url($issuerUri, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : 'the configured issuer';
    }
}
