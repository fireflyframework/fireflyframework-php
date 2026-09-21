<?php

declare(strict_types=1);

use Firefly\Observability\ObservabilityServiceProvider;
use Firefly\Observability\ObservabilityWiringProvider;
use Firefly\Observability\Tracing\NoOpTracer;
use Firefly\Observability\Tracing\OpenTelemetry\OpenTelemetryTracer;
use Firefly\Observability\Tracing\SpanKind;
use Firefly\Observability\Tracing\Tracer;
use Illuminate\Foundation\Application;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;

/**
 * The same bare boot RealProviderBootTest uses (see its docblock for why a filesystem-free log channel is
 * seeded), with the tracing flag under test and an optional in-memory exporter bound the way an application
 * or a Testbench suite would bind one.
 *
 * @param  array<string, mixed>  $tracing
 * @param  array<class-string, object>  $bindings
 */
function bootTracing(array $tracing, array $bindings = []): Application
{
    return fireflyApplication(
        config: [
            'app' => ['name' => 'Ledger', 'env' => 'testing'],
            'firefly' => ['observability' => ['tracing' => $tracing]],
            'logging' => ['default' => 'test', 'channels' => ['test' => ['driver' => 'errorlog']]],
        ],
        providers: [ObservabilityServiceProvider::class, ObservabilityWiringProvider::class],
        bindings: $bindings,
    );
}

it('binds the NoOp tracer and no provider while tracing is off — the default', function () {
    $app = bootTracing([]);

    expect($app->make(Tracer::class))->toBeInstanceOf(NoOpTracer::class)
        ->and($app->bound(TracerProviderInterface::class))->toBeFalse();
});

it('binds the OpenTelemetry tracer ahead of the NoOp when tracing is enabled', function () {
    $app = bootTracing(['enabled' => true]);

    expect($app->make(Tracer::class))->toBeInstanceOf(OpenTelemetryTracer::class)
        ->and($app->make(TracerProviderInterface::class))->toBeInstanceOf(TracerProviderInterface::class);
});

it('uses a SpanExporterInterface the application bound, and stamps service.name and the environment on the resource', function () {
    $exporter = new InMemoryExporter;
    $app = bootTracing(['enabled' => true, 'service-name' => 'ledger-api', 'resource-attributes' => ['deployment.region' => 'eu-west-1']], [SpanExporterInterface::class => $exporter]);

    $app->make(Tracer::class)->startSpan('work', SpanKind::Internal)->end();

    /** @var list<ImmutableSpan> $spans */
    $spans = $exporter->getSpans();
    $resource = $spans[0]->getResource()->getAttributes()->toArray();

    expect($spans)->toHaveCount(1)
        ->and($resource['service.name'])->toBe('ledger-api')
        ->and($resource['deployment.environment.name'])->toBe('testing')
        ->and($resource['deployment.region'])->toBe('eu-west-1');
});
