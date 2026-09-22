<?php

declare(strict_types=1);

namespace Firefly\Observability\Tracing\OpenTelemetry;

use Firefly\Config\Config;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOffSampler;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\SDK\Trace\Sampler\ParentBased;
use OpenTelemetry\SDK\Trace\Sampler\TraceIdRatioBasedSampler;
use OpenTelemetry\SDK\Trace\SamplerInterface;

/**
 * The sampler `firefly.observability.tracing.sampler.type` names, always wrapped in ParentBased — so an inbound
 * traceparent's sampled flag is honoured whatever the local decision would have been, which is what keeps a
 * distributed trace whole (the OTel SDK's own default, and Spring's `management.tracing.sampling.probability`
 * semantics).
 */
final class TraceSamplerFactory
{
    public static function fromConfig(Config $config): SamplerInterface
    {
        $type = $config->string('firefly.observability.tracing.sampler.type', 'always_on');

        return new ParentBased(match ($type) {
            'always_on' => new AlwaysOnSampler,
            'always_off' => new AlwaysOffSampler,
            'ratio' => new TraceIdRatioBasedSampler(self::ratio($config)),
            default => throw new ConfigurationException(
                "Unknown tracing sampler '{$type}' (firefly.observability.tracing.sampler.type); use always_on, always_off or ratio.",
            ),
        });
    }

    private static function ratio(Config $config): float
    {
        $raw = $config->get('firefly.observability.tracing.sampler.ratio', 1.0);

        if (! is_numeric($raw) || (float) $raw < 0.0 || (float) $raw > 1.0) {
            throw new ConfigurationException(
                'firefly.observability.tracing.sampler.ratio must be a number between 0 and 1 (the share of new traces to keep).',
            );
        }

        return (float) $raw;
    }
}
