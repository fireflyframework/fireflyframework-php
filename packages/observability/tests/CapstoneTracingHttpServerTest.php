<?php

declare(strict_types=1);

use Firefly\Observability\Tests\Support\TracingCapstoneTestCase;
use Firefly\Observability\Tracing\OpenTelemetry\OpenTelemetryTracer;
use Firefly\Observability\Tracing\Tracer;
use Firefly\Observability\Web\TracingFilter;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Foundation\Http\Kernel as FoundationHttpKernel;
use OpenTelemetry\API\Trace\SpanKind as OtelSpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SDK\Trace\EventInterface;

uses(TracingCapstoneTestCase::class);

/**
 * Declared `object` rather than letting Larastan narrow make() to Testbench's own Kernel subclass — the same
 * reason FilterChainRegistrar::resolveHttpKernel() is typed that way: the instanceof below must be a real
 * narrowing, not one PHPStan reports as always true.
 */
function tracingKernel(TracingCapstoneTestCase $case): object
{
    return $case->app()->make(HttpKernelContract::class);
}

it('binds the OpenTelemetry tracer and pushes the discovered TracingFilter onto the real HTTP kernel', function () {
    /** @var TracingCapstoneTestCase $this */
    $kernel = tracingKernel($this);

    expect($this->app()->make(Tracer::class))->toBeInstanceOf(OpenTelemetryTracer::class)
        ->and($kernel)->toBeInstanceOf(FoundationHttpKernel::class)
        ->and($kernel instanceof FoundationHttpKernel && $kernel->hasMiddleware(TracingFilter::class))->toBeTrue();
});

it('exports one SERVER span per request, named by the route template, with the ids the request saw in Context', function () {
    /** @var TracingCapstoneTestCase $this */
    $response = $this->getJson('/demo/7')->assertStatus(200);

    $span = $this->spanNamed('GET /demo/{id}');
    expect($span)->not->toBeNull()
        ->and($span?->getKind())->toBe(OtelSpanKind::KIND_SERVER)
        ->and($span?->getAttributes()->toArray())->toMatchArray([
            'http.request.method' => 'GET',
            'url.path' => '/demo/7',
            'http.route' => '/demo/{id}',
            'http.response.status_code' => 200,
        ])
        ->and($span?->getResource()->getAttributes()->get('service.name'))->toBe('capstone')
        ->and($response->json('traceId'))->toBe($span?->getTraceId())
        ->and($response->json('spanId'))->toBe($span?->getSpanId())
        ->and($this->spans())->toHaveCount(1);
});

it('continues an inbound traceparent and stamps the same trace id on the /actuator/httpexchanges row', function () {
    /** @var TracingCapstoneTestCase $this */
    $this->withHeaders(['traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01'])
        ->getJson('/demo/9')
        ->assertStatus(200)
        ->assertJsonPath('traceId', '4bf92f3577b34da6a3ce929d0e0e4736');

    $span = $this->spanNamed('GET /demo/{id}');
    expect($span?->getTraceId())->toBe('4bf92f3577b34da6a3ce929d0e0e4736')
        ->and($span?->getParentSpanId())->toBe('00f067aa0ba902b7');

    $this->getJson('/actuator/httpexchanges')
        ->assertStatus(200)
        ->assertJsonPath('exchanges.0.uri', '/demo/{id}')
        ->assertJsonPath('exchanges.0.traceId', '4bf92f3577b34da6a3ce929d0e0e4736');
});

it('marks a thrown request ERROR with the exception recorded, and the problem renderer still answers', function () {
    /** @var TracingCapstoneTestCase $this */
    $this->getJson('/boom')->assertStatus(500);

    $span = $this->spanNamed('GET /boom');
    expect($span?->getStatus()->getCode())->toBe(StatusCode::STATUS_ERROR)
        ->and($span?->getAttributes()->get('http.response.status_code'))->toBe(500)
        ->and(array_map(static fn (EventInterface $event): string => $event->getName(), $span?->getEvents() ?? []))->toContain('exception');
});

/**
 * abort(404) throws a NotFoundHttpException the routing pipeline renders and attaches to the 404 it hands the
 * outer middleware — the same shape as /boom, one status class down. The OTel HTTP server-span rule says a 4xx
 * stays Unset, and the exchange row still records it like any other request.
 */
it('leaves a rendered 4xx Unset with no exception event, and the exchange row still records the 404', function () {
    /** @var TracingCapstoneTestCase $this */
    $this->getJson('/missing')->assertStatus(404);

    $span = $this->spanNamed('GET /missing');
    expect($span)->not->toBeNull()
        ->and($span?->getStatus()->getCode())->toBe(StatusCode::STATUS_UNSET)
        ->and($span?->getAttributes()->get('http.response.status_code'))->toBe(404)
        ->and(array_map(static fn (EventInterface $event): string => $event->getName(), $span?->getEvents() ?? []))->not->toContain('exception');

    $this->getJson('/actuator/httpexchanges')
        ->assertStatus(200)
        ->assertJsonPath('exchanges.0.uri', '/missing')
        ->assertJsonPath('exchanges.0.status', 404)
        ->assertJsonPath('exchanges.0.traceId', $span?->getTraceId());
});
