<?php

declare(strict_types=1);

namespace Firefly\Observability\Endpoint;

use Firefly\Actuator\Endpoint\ActuatorEndpoint;
use Firefly\Actuator\Endpoint\EndpointRequest;
use Firefly\Actuator\Endpoint\EndpointResponse;
use Firefly\Container\Attributes\Component;
use Firefly\Container\Attributes\Lazy;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Firefly\Observability\Metrics\MeterRegistry;
use Firefly\Observability\Prometheus\PrometheusTextFormat;

/**
 * Implements actuator's endpoint contract and registers into the actuator registry ONLY when metrics are enabled
 * (§7 risk 1) — #[ConditionalOnProperty(firefly.observability.metrics.enabled)], the SAME property that gates the
 * MeterRegistry bean itself (order-independent, unlike gating on MeterRegistry's presence via
 * #[ConditionalOnBean]: that would evaluate at ConditionPassTwo BEFORE ObservabilityAutoConfiguration's
 * #[Order(500)] registers MeterRegistry, wrongly dropping this endpoint even when metrics are enabled).
 * Answers /actuator/prometheus with 0.0.4 text.
 *
 * #[Lazy] is REQUIRED, not decorative — the same reasoning as BeansEndpoint/ConditionsEndpoint (T8):
 * ObservabilityAutoConfiguration binds MeterRegistry as a #[Bean] resolved at BootPhase::EagerSingletons
 * (900) — the SAME phase EagerSingletonsPass would eagerly build this #[Component] at, were it not
 * #[Lazy]. That resolution order between two phase-900 candidates is unreliable, so a plain (non-lazy)
 * PrometheusEndpoint could be constructed before MeterRegistry exists in the container, crashing boot.
 * #[Lazy] excludes it from the eager pass; ActuatorRouteRegistrar's own resolve at
 * BootPhase::WiringPasses (1000) — strictly AFTER EagerSingletons — then always finds MeterRegistry
 * already bound.
 *
 * Return type is narrowed to the non-nullable EndpointResponse (the same covariant-narrowing idiom
 * BeansEndpoint/ConditionsEndpoint/InfoEndpoint use): /prometheus has no sub-resource concept to 404
 * on, handle() always produces a body, and PHPStan (level max) flags the wider nullable type as dead
 * code otherwise.
 */
#[Component]
#[ConditionalOnProperty(name: 'firefly.observability.metrics.enabled', havingValue: 'true', matchIfMissing: true)]
#[Lazy]
final class PrometheusEndpoint implements ActuatorEndpoint
{
    public function __construct(
        private readonly MeterRegistry $registry,
        private readonly PrometheusTextFormat $format,
    ) {}

    public function endpointId(): string
    {
        return 'prometheus';
    }

    public function enabled(): bool
    {
        return true;
    }

    public function handle(EndpointRequest $request): EndpointResponse
    {
        return EndpointResponse::text($this->format->render($this->registry->meters()), 200, 'text/plain; version=0.0.4');
    }
}
