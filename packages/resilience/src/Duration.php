<?php

declare(strict_types=1);

namespace Firefly\Resilience;

use Firefly\Kernel\Exception\Framework\ConfigurationException;

/**
 * Parses a human duration string to a float number of seconds. Grammar: an optional-decimal magnitude with
 * an optional unit suffix (ms/s/m/h); a bare number is seconds (pyfly parity). Shared by resilience config
 * (wait-duration, timeout, …) and scheduling (lock-ttl, fixed-rate).
 */
final class Duration
{
    public static function parse(string $value): float
    {
        if (preg_match('/^\s*(\d+(?:\.\d+)?)\s*(ms|s|m|h)?\s*$/', $value, $matches) !== 1) {
            throw new ConfigurationException(
                "Invalid duration [{$value}]. Use e.g. '250ms', '30s', '5m', '1h', or a bare number of seconds.",
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
