<?php

declare(strict_types=1);

use Firefly\Context\Boot\ApplicationContext;
use Firefly\Eda\EdaServiceProvider;
use Firefly\Eda\EdaWiringProvider;
use Firefly\Observability\ObservabilityServiceProvider;
use Firefly\Observability\ObservabilityWiringProvider;
use Firefly\Observability\Orchestration\MeterRegistryOrchestrationMetrics;
use Firefly\Observability\Orchestration\TracerOrchestrationTracing;
use Firefly\Orchestration\Event\NoOpOrchestrationMetrics;
use Firefly\Orchestration\Event\NoOpOrchestrationTracing;
use Firefly\Orchestration\Event\OrchestrationMetrics;
use Firefly\Orchestration\Event\OrchestrationTracing;
use Firefly\Orchestration\OrchestrationServiceProvider;
use Firefly\Orchestration\OrchestrationWiringProvider;
use Firefly\Resilience\ResilienceServiceProvider;
use Firefly\Scheduling\SchedulingServiceProvider;
use Firefly\Scheduling\SchedulingWiringProvider;

/** @param array<string, mixed> $observability */
function bootOrchestrationObservability(array $observability): ApplicationContext
{
    return bootFireflyApp(
        [
            'firefly' => ['observability' => $observability],
            'logging' => ['default' => 'test', 'channels' => ['test' => ['driver' => 'errorlog']]],
        ],
        [
            ResilienceServiceProvider::class, SchedulingServiceProvider::class, SchedulingWiringProvider::class,
            EdaServiceProvider::class, EdaWiringProvider::class,
            ObservabilityServiceProvider::class, ObservabilityWiringProvider::class,
            OrchestrationServiceProvider::class, OrchestrationWiringProvider::class,
        ],
        needs: ['cache'],
    );
}

it('backs the orchestration metrics port with the meter registry unless metrics are off', function (): void {
    expect(bootOrchestrationObservability([])->get(OrchestrationMetrics::class))->toBeInstanceOf(MeterRegistryOrchestrationMetrics::class)
        ->and(bootOrchestrationObservability(['metrics' => ['enabled' => false]])->get(OrchestrationMetrics::class))->toBeInstanceOf(NoOpOrchestrationMetrics::class);
});

it('traces orchestrations only under the tracing master gate and the orchestration switch', function (): void {
    expect(bootOrchestrationObservability(['tracing' => ['enabled' => true]])->get(OrchestrationTracing::class))->toBeInstanceOf(TracerOrchestrationTracing::class)
        ->and(bootOrchestrationObservability([])->get(OrchestrationTracing::class))->toBeInstanceOf(NoOpOrchestrationTracing::class)
        ->and(bootOrchestrationObservability(['tracing' => ['enabled' => true, 'orchestration' => ['enabled' => false]]])->get(OrchestrationTracing::class))->toBeInstanceOf(NoOpOrchestrationTracing::class);
});
