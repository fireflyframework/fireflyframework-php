<?php

declare(strict_types=1);

namespace Firefly\Observability\Metrics;

use Firefly\Config\Config;
use Firefly\Kernel\Exception\Framework\ConfigurationException;

/**
 * Micrometer's DistributionStatisticConfig, reduced to the one statistic Prometheus can aggregate across
 * processes: fixed histogram buckets. A timer whose name has buckets exposes as a Prometheus `histogram`
 * (`_bucket{le=…}`, `_count`, `_sum`, the shape `histogram_quantile()` wants); one without stays the `summary`
 * (`_count`, `_sum`) M12 shipped.
 *
 * `firefly.observability.metrics.distribution.buckets` is the global list (seconds, ascending after
 * normalisation); `distribution.per-meter.<name>` overrides it for one meter name — and an EMPTY per-meter
 * list turns that meter back into a summary. Bounds are keyed by meter NAME, never by tag set, because a
 * Prometheus family has one type and one bucket layout; that is also why the registries ask this object for
 * the buckets when a timer is CREATED, not on every record.
 *
 * Default: no buckets. Not because buckets are dangerous but because turning a summary into a histogram
 * changes a scrape's `# TYPE` line and adds series, and an upgrade must not do that to a running dashboard
 * without being asked. The list Prometheus clients ship by default is the one worth starting from:
 * [0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1, 2.5, 5, 10].
 */
final class DistributionStatisticConfig
{
    public const string BUCKETS_KEY = 'firefly.observability.metrics.distribution.buckets';

    public const string PER_METER_KEY = 'firefly.observability.metrics.distribution.per-meter';

    /**
     * @param  list<float>  $buckets  ascending upper bounds in seconds
     * @param  array<string, list<float>>  $perMeter  meter name => its own bounds
     */
    public function __construct(
        private readonly array $buckets = [],
        private readonly array $perMeter = [],
    ) {}

    public static function none(): self
    {
        return new self;
    }

    public static function fromConfig(Config $config): self
    {
        $buckets = self::normalise($config->array('firefly.observability.metrics.distribution.buckets', []), self::BUCKETS_KEY);

        $perMeter = [];
        foreach ($config->array('firefly.observability.metrics.distribution.per-meter', []) as $meter => $list) {
            $key = self::PER_METER_KEY.'.'.(string) $meter;
            if (! is_string($meter) || $meter === '' || ! is_array($list)) {
                throw new ConfigurationException("{$key} must map a meter name to a list of bucket bounds in seconds.");
            }
            $perMeter[$meter] = self::normalise($list, $key);
        }

        return new self($buckets, $perMeter);
    }

    /** @return list<float> ascending upper bounds in seconds; [] when the meter is a summary */
    public function bucketsFor(string $meterName): array
    {
        return $this->perMeter[$meterName] ?? $this->buckets;
    }

    /**
     * @param  array<mixed>  $raw
     * @return list<float>
     */
    private static function normalise(array $raw, string $key): array
    {
        $bounds = [];
        foreach ($raw as $value) {
            if (! is_int($value) && ! is_float($value) && ! (is_string($value) && is_numeric($value))) {
                throw new ConfigurationException("{$key} must be a list of numbers (bucket upper bounds, in seconds).");
            }
            $seconds = (float) $value;
            if ($seconds <= 0.0 || is_infinite($seconds) || is_nan($seconds)) {
                throw new ConfigurationException("{$key}: every bucket bound must be a positive number of seconds; +Inf is implied.");
            }
            $bounds[] = $seconds;
        }

        $bounds = array_values(array_unique($bounds));
        sort($bounds);

        return $bounds;
    }
}
