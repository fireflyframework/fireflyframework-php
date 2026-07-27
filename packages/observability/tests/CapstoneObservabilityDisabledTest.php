<?php

declare(strict_types=1);

use Firefly\Cqrs\Metrics\CqrsMetrics;
use Firefly\Cqrs\Metrics\NoOpCqrsMetrics;
use Firefly\Observability\Metrics\MeterRegistry;
use Firefly\Observability\Tests\Support\ObservabilityDisabledCapstoneTestCase;

uses(ObservabilityDisabledCapstoneTestCase::class);

/**
 * The property-gate proof (§7 risk 1/4), disabled side: firefly.observability.metrics.enabled=false must leave the
 * M10 NoOpCqrsMetrics bound (ObservabilityAutoConfiguration::cqrsMetrics() backs off via its OWN
 * #[ConditionalOnProperty], so CqrsAutoConfiguration's #[ConditionalOnMissingBean(CqrsMetrics)] default wins
 * instead), bind no MeterRegistry at all, and leave /actuator/prometheus + /actuator/metrics completely unmounted
 * — at the HTTP level, not merely the container-level RealProviderBootTest already covers.
 */
it('leaves the M10 NoOp bound, binds no MeterRegistry, and unmounts /prometheus + /metrics when metrics are disabled', function () {
    /** @var ObservabilityDisabledCapstoneTestCase $this */
    expect($this->app()->make(CqrsMetrics::class))->toBeInstanceOf(NoOpCqrsMetrics::class)
        ->and($this->app()->bound(MeterRegistry::class))->toBeFalse();

    $this->getJson('/actuator/prometheus')->assertStatus(404);
    $this->getJson('/actuator/metrics')->assertStatus(404);
});
