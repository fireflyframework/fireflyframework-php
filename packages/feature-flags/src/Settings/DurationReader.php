<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Settings;

use Firefly\Config\Config;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Resilience\Duration;

/**
 * Reads one `firefly.feature-flags` duration through the Config port and refuses a malformed one, naming its key.
 *
 * The caller writes the key once, in full: `$durations->get('firefly.feature-flags.sources.file.refresh-interval',
 * 5.0)`. That literal is both the key read and the key every refusal names, so the two cannot drift apart. The
 * method is called get() on purpose: tests/ConfigReferenceTest.php and DocsCodeAudit::keyLiterals() find a key by
 * a `->get('firefly.…')` call, so each duration key stays visible to both.
 *
 * Accepted: a duration in the framework grammar (`'500ms'`, `'5s'`, `'PT1M'`, see Duration::parse()) or a finite
 * number of seconds. Null is unset, as everywhere in Config: an absent key, or `env('X')` with X unset, gives the
 * default. Refused: a negative value, a non-finite number, an empty or unparsable string and any other type; and
 * zero too when the caller says why zero is unsafe for that key (`$refuseZeroBecause`, quoted in the refusal).
 *
 * @internal
 */
final readonly class DurationReader
{
    public function __construct(private Config $config) {}

    public function get(string $key, float $default, ?string $refuseZeroBecause = null): float
    {
        $value = $this->config->get($key);
        if ($value === null) {
            return $default;
        }

        if (is_int($value) || is_float($value)) {
            $seconds = (float) $value;
            if (! is_finite($seconds)) {
                throw new ConfigurationException("Configuration key [{$key}] must be a finite number of seconds, got [".var_export($value, true).'].');
            }
        } elseif (is_string($value) && trim($value) !== '') {
            try {
                $seconds = Duration::parse($value);
            } catch (ConfigurationException $exception) {
                throw new ConfigurationException("Configuration key [{$key}] is not a duration this framework can parse. {$exception->getMessage()}", previous: $exception);
            }
        } else {
            throw new ConfigurationException("Configuration key [{$key}] must be a duration such as '5s', '500ms' or 'PT1M', or a number of seconds, got ".get_debug_type($value).'.');
        }

        if ($seconds < 0.0) {
            throw new ConfigurationException("Configuration key [{$key}] must not be negative, got [".var_export($value, true).'].');
        }
        if ($refuseZeroBecause !== null && $seconds <= 0.0) {
            throw new ConfigurationException("Configuration key [{$key}] must be greater than zero, got [".var_export($value, true)."]: {$refuseZeroBecause}.");
        }

        return $seconds;
    }
}
