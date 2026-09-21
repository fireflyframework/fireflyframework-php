<?php

declare(strict_types=1);

namespace Firefly\Observability\Tracing\OpenTelemetry;

use Firefly\Observability\Tracing\Span;
use Firefly\Observability\Tracing\SpanContext;
use Firefly\Observability\Tracing\SpanKind;
use Firefly\Observability\Tracing\SpanStatus;
use Firefly\Observability\Tracing\Tracer;
use OpenTelemetry\API\Trace\Span as OtelSpan;
use OpenTelemetry\API\Trace\SpanContext as OtelSpanContext;
use OpenTelemetry\API\Trace\SpanKind as OtelSpanKind;
use OpenTelemetry\API\Trace\TraceFlags;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\API\Trace\TraceState;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use Throwable;

/**
 * The Tracer port over the OpenTelemetry API. Built over a TracerProviderInterface rather than the SDK's
 * TracerProvider so an application that configures the SDK itself (or the auto-instrumentation extension's
 * global provider) can hand its provider in; OpenTelemetryAutoConfiguration builds the default one from
 * `firefly.observability.tracing.*`.
 *
 * Parent handling is the port's contract: null continues the SDK's current context (nesting), an invalid
 * SpanContext starts a new root (`setParent(false)`), and a valid one — extracted from a carrier by
 * W3CTraceContextPropagator — is converted to a remote OTel SpanContext and wrapped as the parent.
 */
final class OpenTelemetryTracer implements Tracer
{
    public const string SCOPE = 'firefly/observability';

    private readonly TracerInterface $tracer;

    public function __construct(private readonly TracerProviderInterface $provider)
    {
        $this->tracer = $provider->getTracer(self::SCOPE);
    }

    public function provider(): TracerProviderInterface
    {
        return $this->provider;
    }

    public function startSpan(string $name, SpanKind $kind = SpanKind::Internal, array $attributes = [], ?SpanContext $parent = null): Span
    {
        $builder = $this->tracer->spanBuilder($name === '' ? 'unnamed' : $name)->setSpanKind(self::kind($kind));

        // On the builder rather than on the started span, so a sampler that looks at attributes sees them.
        foreach ($attributes as $key => $value) {
            if ($key !== '') {
                $builder->setAttribute($key, $value);
            }
        }

        if ($parent !== null) {
            $builder->setParent($parent->isValid() ? $this->remoteParent($parent) : false);
        }

        return (new OpenTelemetrySpan($builder->startSpan(), null))->activated();
    }

    public function currentSpan(): ?Span
    {
        $current = OtelSpan::getCurrent();

        return $current->getContext()->isValid() ? new OpenTelemetrySpan($current, null) : null;
    }

    /**
     * @template T
     *
     * @param  callable(Span): T  $callback
     * @param  array<string, bool|int|float|string|array<mixed>|null>  $attributes
     * @return T
     */
    public function trace(string $name, callable $callback, SpanKind $kind = SpanKind::Internal, array $attributes = []): mixed
    {
        $span = $this->startSpan($name, $kind, $attributes);

        try {
            return $callback($span);
        } catch (Throwable $e) {
            $span->recordException($e)->setStatus(SpanStatus::Error, $e->getMessage());

            throw $e;
        } finally {
            $span->end();
        }
    }

    private function remoteParent(SpanContext $parent): ContextInterface
    {
        $remote = OtelSpanContext::createFromRemoteParent(
            $parent->traceId,
            $parent->spanId,
            $parent->sampled ? TraceFlags::SAMPLED : TraceFlags::DEFAULT,
            $parent->traceState === '' ? null : new TraceState($parent->traceState),
        );

        return Context::getCurrent()->withContextValue(OtelSpan::wrap($remote));
    }

    /** @return OtelSpanKind::KIND_* */
    private static function kind(SpanKind $kind): int
    {
        return match ($kind) {
            SpanKind::Internal => OtelSpanKind::KIND_INTERNAL,
            SpanKind::Server => OtelSpanKind::KIND_SERVER,
            SpanKind::Client => OtelSpanKind::KIND_CLIENT,
            SpanKind::Producer => OtelSpanKind::KIND_PRODUCER,
            SpanKind::Consumer => OtelSpanKind::KIND_CONSUMER,
        };
    }
}
