<?php

declare(strict_types=1);

namespace Firefly\Observability;

use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Configuration;
use Firefly\Container\Attributes\Order;
use Firefly\Context\Condition\Attributes\ConditionalOnMissingBean;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Firefly\Cqrs\Metrics\CqrsMetrics;
use Firefly\Observability\Cqrs\MeterRegistryCqrsMetrics;
use Firefly\Observability\Metrics\MeterRegistry;
use Firefly\Observability\Metrics\MetricsRecorder;
use Firefly\Observability\Metrics\NoOpMetricsRecorder;
use Firefly\Observability\Metrics\SimpleMeterRegistry;
use Firefly\Observability\Prometheus\PrometheusTextFormat;
use Firefly\Observability\Tracing\NoOpTracer;
use Firefly\Observability\Tracing\Tracer;
use Illuminate\Container\Container;

/**
 * #[Order(500)] is DELIBERATELY below CqrsAutoConfiguration's #[Order(1000)] (§7 risk 4, mirrors
 * SecurityAutoConfiguration): the incremental condition pass registers this class's cqrsMetrics() bean FIRST, so
 * Cqrs's #[ConditionalOnMissingBean(CqrsMetrics)] backs off and the M10 NoOp steps aside. meterRegistry() gates on
 * firefly.observability.metrics.enabled (default ON via matchIfMissing) — disabled → no MeterRegistry, and the
 * metrics endpoints, the MetricsFilter, and cqrsMetrics all back off too because each gates on the SAME property
 * via #[ConditionalOnProperty] (§7 risk 1/4) rather than on MeterRegistry's presence (which would be
 * order-dependent) — and the safe NoOpMetricsRecorder is bound instead. Every bean is #[ConditionalOnMissingBean]
 * so an app override wins.
 */
#[Configuration]
#[Order(500)]
final class ObservabilityAutoConfiguration
{
    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.observability.metrics.enabled', havingValue: 'true', matchIfMissing: true)]
    #[ConditionalOnMissingBean(MeterRegistry::class)]
    public function meterRegistry(): MeterRegistry
    {
        return new SimpleMeterRegistry;
    }

    #[Bean]
    #[ConditionalOnMissingBean(MetricsRecorder::class)]
    public function metricsRecorder(Container $container): MetricsRecorder
    {
        if ($container->bound(MeterRegistry::class)) {
            $registry = $this->resolveRegistry($container);
            if ($registry instanceof MetricsRecorder) {
                return $registry;
            }
        }

        return new NoOpMetricsRecorder;
    }

    /**
     * Resolves the bound MeterRegistry as its CONTRACT type. Routing the resolution through this interface-typed
     * boundary keeps the `instanceof MetricsRecorder` check above honest under static analysis: an analyzer that
     * resolves the container's default binding would narrow make(MeterRegistry::class) to the concrete
     * SimpleMeterRegistry (which implements both contracts) and flag the check — meaningful for a custom registry
     * that records nothing — as statically always-true. Runtime behaviour is identical to calling make() inline.
     */
    private function resolveRegistry(Container $container): MeterRegistry
    {
        return $container->make(MeterRegistry::class);
    }

    #[Bean]
    #[ConditionalOnMissingBean(PrometheusTextFormat::class)]
    public function prometheusTextFormat(): PrometheusTextFormat
    {
        return new PrometheusTextFormat;
    }

    #[Bean]
    #[ConditionalOnMissingBean(Tracer::class)]
    public function tracer(): Tracer
    {
        return new NoOpTracer;
    }

    #[Bean]
    #[ConditionalOnMissingBean(CqrsMetrics::class)]
    #[ConditionalOnProperty(name: 'firefly.observability.metrics.enabled', havingValue: 'true', matchIfMissing: true)]
    public function cqrsMetrics(MetricsRecorder $recorder): CqrsMetrics
    {
        return new MeterRegistryCqrsMetrics($recorder);
    }
}
