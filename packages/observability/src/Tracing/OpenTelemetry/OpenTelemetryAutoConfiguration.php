<?php

declare(strict_types=1);

namespace Firefly\Observability\Tracing\OpenTelemetry;

use Firefly\Config\Config;
use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Configuration;
use Firefly\Container\Attributes\Order;
use Firefly\Context\Condition\Attributes\ConditionalOnClass;
use Firefly\Context\Condition\Attributes\ConditionalOnMissingBean;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Firefly\Observability\Tracing\Tracer;
use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application as ApplicationContract;
use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\Contrib\Otlp\SpanExporter as OtlpSpanExporter;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
use OpenTelemetry\SemConv\ResourceAttributes;

/**
 * Spring Boot's OpenTelemetryAutoConfiguration, ported: a TracerProvider from configuration, and the port's
 * Tracer over it — only when the SDK is loadable (#[ConditionalOnClass], so `composer require
 * open-telemetry/sdk` is the whole install) AND `firefly.observability.tracing.enabled` is on (default off:
 * a framework must not start exporting telemetry because a library appeared in vendor/).
 *
 * #[Order(400)] is DELIBERATELY below ObservabilityAutoConfiguration's #[Order(500)]: the incremental condition
 * pass registers this class's tracer() first, so the NoOpTracer bean behind #[ConditionalOnMissingBean(Tracer)]
 * backs off — the same precedence trick MeterRegistryCqrsMetrics uses against CQRS's NoOp. When either
 * condition fails, /actuator/conditions names it and the NoOp stays: every instrumentation site then costs a
 * few method calls and publishes no ids.
 *
 * A SpanExporterInterface bean the application (or a test) bound wins over the `exporter` key — Spring's
 * "a SpanExporter bean is picked up" contract, and what lets a Testbench suite assert spans through the SDK's
 * own InMemoryExporter. The OTLP exporter is batched and flushed when the application terminates (PHP-FPM's
 * request end, Octane's per-request terminate); console and in-memory exporters are synchronous.
 */
#[Configuration]
#[Order(400)]
#[ConditionalOnClass(TracerProvider::class)]
#[ConditionalOnProperty(name: 'firefly.observability.tracing.enabled', havingValue: 'true')]
final class OpenTelemetryAutoConfiguration
{
    #[Bean]
    #[ConditionalOnMissingBean(TracerProviderInterface::class)]
    public function tracerProvider(Container $container, Config $config): TracerProviderInterface
    {
        $exporter = $container->bound(SpanExporterInterface::class)
            ? $this->boundExporter($container)
            : TraceExporterFactory::fromConfig($config);

        $processors = [];
        if ($exporter !== null) {
            $processors[] = $exporter instanceof OtlpSpanExporter
                ? new BatchSpanProcessor($exporter, Clock::getDefault())
                : new SimpleSpanProcessor($exporter);
        }

        $provider = new TracerProvider($processors, TraceSamplerFactory::fromConfig($config), $this->resource($config));

        if ($container instanceof ApplicationContract) {
            $container->terminating(static function () use ($provider): void {
                $provider->forceFlush();
            });
        }

        return $provider;
    }

    #[Bean]
    #[ConditionalOnMissingBean(Tracer::class)]
    public function tracer(TracerProviderInterface $provider): Tracer
    {
        return new OpenTelemetryTracer($provider);
    }

    /**
     * Resolved through an interface-typed boundary for the same reason ObservabilityAutoConfiguration::
     * resolveRegistry() is: it keeps the static type honest about what a container hands back.
     */
    private function boundExporter(Container $container): SpanExporterInterface
    {
        return $container->make(SpanExporterInterface::class);
    }

    /**
     * The SDK's default resource (host, process, telemetry.sdk.*) plus what identifies THIS application in a
     * backend: service.name (tracing.service-name, else app.name), deployment.environment.name (app.env), and
     * whatever `resource-attributes` adds (region, version, team). Non-scalar values are dropped rather than
     * serialized, because a resource attribute is a label, not a document.
     */
    private function resource(Config $config): ResourceInfo
    {
        $name = $config->string('firefly.observability.tracing.service-name', '');
        if ($name === '') {
            $name = $config->string('app.name', 'laravel');
        }

        $attributes = [
            ResourceAttributes::SERVICE_NAME => $name,
            'deployment.environment.name' => $config->string('app.env', 'production'),
        ];

        foreach ($config->array('firefly.observability.tracing.resource-attributes', []) as $key => $value) {
            if (is_string($key) && $key !== '' && is_scalar($value)) {
                $attributes[$key] = $value;
            }
        }

        return ResourceInfoFactory::defaultResource()->merge(ResourceInfo::create(Attributes::create($attributes)));
    }
}
