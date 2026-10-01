<?php

declare(strict_types=1);

namespace Firefly\Resilience;

use Firefly\Kernel\Exception\Framework\ConfigurationException;

/**
 * Parses a duration string to a float number of seconds. Two grammars are accepted:
 *
 * - the short form: an optional-decimal magnitude with an optional unit suffix (ms/s/m/h); a bare number is
 *   seconds (pyfly parity);
 * - ISO-8601 as java.time.Duration reads it (Spring parity): `P[nD][T[nH][nM][n[.n]S]]`, case-insensitive, with at
 *   least one component. Years, months and weeks are refused, as java.time.Duration refuses them: their length
 *   in seconds is not fixed.
 *
 * Shared by resilience config (wait-duration, timeout, …) and scheduling (lock-ttl, fixed-rate, initial-delay).
 */
final class Duration
{
    private const string ISO_8601 = '/^\s*P(?:(\d+)D)?(?:T(?=\d)(?:(\d+)H)?(?:(\d+)M)?(?:(\d+(?:\.\d+)?)S)?)?\s*$/i';

    public static function parse(string $value): float
    {
        if (preg_match(self::ISO_8601, $value, $iso) === 1 && preg_match('/\d/', $value) === 1) {
            // A trailing `T` with nothing after it is caught by the lookahead; `P` alone has no digit at all.
            return (float) ($iso[1] ?? 0) * 86400.0
                + (float) ($iso[2] ?? 0) * 3600.0
                + (float) ($iso[3] ?? 0) * 60.0
                + (float) ($iso[4] ?? 0);
        }

        if (preg_match('/^\s*(\d+(?:\.\d+)?)\s*(ms|s|m|h)?\s*$/', $value, $matches) !== 1) {
            throw new ConfigurationException(
                "Invalid duration [{$value}]. Use e.g. '250ms', '30s', '5m', '1h', a bare number of seconds, "
                ."or an ISO-8601 duration such as 'PT5M'.",
            );
        }

        $magnitude = (float) $matches[1];

        return match ($matches[2] ?? '') {
            'ms' => $magnitude / 1000.0,
            'm' => $magnitude * 60.0,
            'h' => $magnitude * 3600.0,
            default => $magnitude, // 's' or a bare number
        };
    }
}
