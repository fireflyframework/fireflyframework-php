<?php

declare(strict_types=1);

namespace Firefly\Observability\Tests\Support;

/**
 * The metrics-DISABLED sibling of ObservabilityCapstoneTestCase (the actuator ActuatorDisabledCapstoneTestCase /
 * eda-messaging EdaQueueCapstoneTestCase idiom): firefly.observability.metrics.enabled is read by
 * ObservabilityAutoConfiguration and every #[ConditionalOnProperty]-gated component at BOOT time, so proving "no
 * MeterRegistry / NoOp CqrsMetrics / no /prometheus route when metrics are off" needs its OWN boot with the flag
 * off from the start — flipping it inside a test body cannot un-bind a bean or un-mount a route already resolved.
 */
class ObservabilityDisabledCapstoneTestCase extends ObservabilityCapstoneTestCase
{
    protected function metricsEnabled(): bool
    {
        return false;
    }
}
