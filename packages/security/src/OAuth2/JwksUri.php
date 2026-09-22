<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2;

/**
 * Whether a JWKS URI names THIS application — the question SecurityAutoConfiguration asks before deciding to
 * fetch the keys over HTTP from the very process that is asking.
 *
 * "Own" is deliberately narrow. The path must be the well-known one this application would serve, and the
 * authority must either match `app.url`'s host and port or be a loopback address on the port this
 * application listens on (`firefly.server.port`): a loopback address on our own listening port can be nobody
 * else. Anything looser — a bare hostname match, any loopback port — would let a JWKS URI that happens to
 * share a host with the application be answered from the wrong key set.
 */
final class JwksUri
{
    /** The path an application serves its own key set at, per RFC 8414's well-known convention. */
    public const string WELL_KNOWN_PATH = '/.well-known/jwks.json';

    /**
     * @param  string  $appUrl  `app.url`, the address this application says it is served at
     * @param  int  $serverPort  `firefly.server.port`, the port this application listens on (0 = unknown)
     */
    public static function isOwn(string $jwksUri, string $appUrl, int $serverPort, string $path = self::WELL_KNOWN_PATH): bool
    {
        $uri = parse_url($jwksUri);

        if ($uri === false || ($uri['path'] ?? '') !== $path || ! isset($uri['host'])) {
            return false;
        }

        $host = strtolower($uri['host']);
        $port = $uri['port'] ?? self::defaultPort($uri['scheme'] ?? '');

        if ($serverPort > 0 && $port === $serverPort && self::isLoopback($host)) {
            return true;
        }

        $app = parse_url($appUrl);

        if ($app === false || ! isset($app['host'])) {
            return false;
        }

        return strtolower($app['host']) === $host
            && ($app['port'] ?? self::defaultPort($app['scheme'] ?? '')) === $port;
    }

    private static function isLoopback(string $host): bool
    {
        return $host === 'localhost'
            || $host === '::1'
            || $host === '[::1]'
            || str_starts_with($host, '127.');
    }

    private static function defaultPort(string $scheme): int
    {
        return match (strtolower($scheme)) {
            'https' => 443,
            'http' => 80,
            default => 0,
        };
    }
}
