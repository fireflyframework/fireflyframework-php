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
 *
 * TWO KINDS, ONE TYPE. `at()` is the fetch failing — a refused connection, a timeout, a 5xx: the provider is
 * down at that moment, and the next attempt may well succeed, so `$transient` is true. `invalid()` is a document
 * that arrived and cannot be used — a foreign `issuer`, no `authorization_endpoint`, a body that is not JSON:
 * nothing about retrying changes what the provider publishes or what `issuer_uri` says, so `$transient` is
 * false. Both are the same 503 on the wire (the request cannot be served either way, and neither is the
 * caller's fault), and `OidcDiscovery` caches neither; the flag is for the code paths that DEGRADE instead of
 * failing — the login page omitting a provider — so they can tell an outage worth a warning from a
 * misconfiguration that deserves an error and will not clear on its own.
 */
final class ProviderDiscoveryException extends ServiceUnavailableException
{
    public const string CODE = 'OIDC_DISCOVERY_UNAVAILABLE';

    public function __construct(
        string $message,
        public readonly bool $transient = true,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, self::CODE, $previous);
    }

    /** The fetch failed: transient, with the cause. */
    public static function at(string $issuerUri, Throwable $cause): self
    {
        return new self(
            sprintf('The OpenID Connect discovery document of %s could not be fetched, so the provider cannot be used right now. Try again in a moment.', self::host($issuerUri)),
            transient: true,
            previous: $cause,
        );
    }

    /** The document arrived and is unusable: not transient, and no retry will make it so. */
    public static function invalid(string $issuerUri, string $reason): self
    {
        return new self(
            sprintf('The OpenID Connect discovery document of %s cannot be used: %s', self::host($issuerUri), $reason),
            transient: false,
        );
    }

    private static function host(string $issuerUri): string
    {
        $host = parse_url($issuerUri, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : 'the configured issuer';
    }
}
