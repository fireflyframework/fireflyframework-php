<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Settings;

use Firefly\Config\Config;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Resilience\Duration;

/**
 * Reads one `firefly.orchestration` duration through the Config port and refuses a malformed one, naming its
 * key (the FeatureFlags DurationReader shape). The caller writes the key once, in full, as the first argument
 * of get() — tests/ConfigReferenceTest.php finds keys by `->get('firefly.…')`, so every duration key stays
 * visible to the reference guard. Accepted: the framework grammar ('250ms', '30s', '5m', '1h', 'P7D') or a
 * number of seconds. Null is unset. Negative is refused; zero is refused when the caller says why.
 *
 * @internal
 */
final readonly class DurationReader
{
    public function __construct(private Config $config) {}

    public function get(string $key, float $default, ?string $refuseZeroBecause = null): float
    {
        $value = $this->config->get($key);
        if ($value === null || $value === '') {
            return $default;
        }

        if (is_int($value) || is_float($value)) {
            $seconds = (float) $value;
        } elseif (is_string($value)) {
            try {
                $seconds = Duration::parse(ltrim($value, '-'));
            } catch (ConfigurationException $exception) {
                throw new ConfigurationException("Configuration key [{$key}] is not a duration this framework can parse. {$exception->getMessage()}", previous: $exception);
            }
            if (str_starts_with(trim($value), '-')) {
                $seconds = -$seconds;
            }
        } else {
            throw new ConfigurationException("Configuration key [{$key}] must be a duration such as '30s', '5m' or 'PT1H', or a number of seconds, got ".get_debug_type($value).'.');
        }

        if (! is_finite($seconds) || $seconds < 0.0) {
            throw new ConfigurationException("Configuration key [{$key}] must not be negative, got [".var_export($value, true).'].');
        }
        if ($refuseZeroBecause !== null && $seconds <= 0.0) {
            throw new ConfigurationException("Configuration key [{$key}] must be greater than zero: {$refuseZeroBecause}.");
        }

        return $seconds;
    }
}
