<?php

declare(strict_types=1);

namespace Firefly\Messaging\Container;

use Firefly\Kernel\Exception\Framework\ConfigurationException;

/**
 * Reads a duration setting: a number of seconds, or `250ms` / `30s` / `5m` / `1h` — the framework's duration
 * grammar, re-stated here because firefly/messaging depends on no capability package (firefly/resilience owns the
 * full parser). null means "unset" and yields the default; anything else that is not a non-negative duration
 * refuses the boot, naming the key.
 */
final class Seconds
{
    public static function parse(mixed $value, float $default, string $key): float
    {
        if ($value === null) {
            return $default;
        }

        if ((is_int($value) || is_float($value)) && $value >= 0) {
            return (float) $value;
        }

        if (is_string($value) && preg_match('/^(\d+(?:\.\d+)?)(ms|s|m|h)?$/', trim($value), $match) === 1) {
            $number = (float) $match[1];

            return match ($match[2] ?? 's') {
                'ms' => $number / 1000,
                'm' => $number * 60,
                'h' => $number * 3600,
                default => $number,
            };
        }

        throw new ConfigurationException("{$key} must be a non-negative duration (seconds, or 250ms / 30s / 5m / 1h); got ".var_export($value, true).'.');
    }
}
