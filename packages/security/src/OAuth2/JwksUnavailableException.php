<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2;

use Firefly\Kernel\Exception\Infrastructure\ServiceUnavailableException;
use Throwable;

/**
 * The token-signing keys could not be fetched, so no bearer token can be verified.
 *
 * A 503 and not a 401, because the caller's token was never examined: the keys it would have been checked
 * against did not arrive. OAuth2ResourceServerFilter used to wrap everything `JWT::decode()` threw — the key
 * fetch included — in INVALID_TOKEN, so an unreachable issuer told every caller their token was bad, and a
 * well-behaved client rotated a perfectly good token. The filter now resolves the keys BEFORE the try that
 * maps decoding failures, and this type reaches the renderer as what it is.
 *
 * The HOST is named because an operator reading the response needs to know WHICH upstream is down; the full
 * URI is not, because a query string on a JWKS URI is where a tenant hint or a key would sit. The cause rides
 * along as `previous` for the log.
 */
final class JwksUnavailableException extends ServiceUnavailableException
{
    public const string CODE = 'JWKS_UNAVAILABLE';

    public static function at(string $jwksUri, Throwable $cause): self
    {
        $host = parse_url($jwksUri, PHP_URL_HOST);

        return new self(
            sprintf(
                'The token signing keys at %s could not be fetched, so no bearer token can be verified right now. Try again in a moment.',
                is_string($host) && $host !== '' ? $host : 'the configured JWKS URI',
            ),
            self::CODE,
            $cause,
        );
    }
}
