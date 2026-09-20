<?php

declare(strict_types=1);

use Firefly\Observability\Tests\Support\TracingCapstoneTestCase;
use Firefly\Observability\Tracing\OpenTelemetry\OpenTelemetryTracer;
use Firefly\Observability\Tracing\Tracer;
use Firefly\Observability\Web\TracingFilter;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Foundation\Http\Kernel as FoundationHttpKernel;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use OpenTelemetry\API\Trace\SpanKind as OtelSpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SDK\Trace\EventInterface;
use OpenTelemetry\SDK\Trace\ImmutableSpan;

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

it('gives an outbound Http client call a CLIENT span under the request span and sends traceparent', function () {
    /** @var TracingCapstoneTestCase $this */
    Http::fake(['https://downstream.test/*' => Http::response('pong', 200)]);

    $this->getJson('/outbound')->assertStatus(200)->assertJsonPath('body', 'pong');

    $server = $this->spanNamed('GET /outbound');
    $client = $this->spanNamed('GET');

    expect($server)->not->toBeNull()
        ->and($client?->getKind())->toBe(OtelSpanKind::KIND_CLIENT)
        ->and($client?->getParentSpanId())->toBe($server?->getSpanId())
        ->and($client?->getTraceId())->toBe($server?->getTraceId())
        ->and($client?->getAttributes()->toArray())->toMatchArray([
            'http.request.method' => 'GET',
            'server.address' => 'downstream.test',
            'url.path' => '/api/ping',
            'http.response.status_code' => 200,
        ]);

    Http::assertSentCount(1);
    Http::assertSent(static fn (Request $request): bool => $request->hasHeader('traceparent', '00-'.$server?->getTraceId().'-'.$client?->getSpanId().'-01'));
});

/**
 * Http::failedConnection() builds its message the way Guzzle 7's CurlFactory does — `... for <uri>` with the
 * query intact — so this is the real pipeline carrying a signed URL's secret to the span's doorstep. What the
 * exporter receives must have the query stripped from the exception event (message and stacktrace header) and
 * the status description, while the attributes the span does carry are unaffected.
 */
it('keeps the query of a failed outbound request out of the CLIENT span\'s exception event and status', function () {
    /** @var TracingCapstoneTestCase $this */
    Http::fake(['https://downstream.test/*' => Http::failedConnection()]);

    $this->getJson('/outbound-signed')->assertStatus(200)->assertJsonPath('error', 'downstream unreachable');

    $client = $this->spanNamed('GET');
    $events = $client?->getEvents() ?? [];
    expect($client?->getKind())->toBe(OtelSpanKind::KIND_CLIENT)
        ->and($client?->getStatus()->getCode())->toBe(StatusCode::STATUS_ERROR)
        ->and($client?->getStatus()->getDescription())->toEndWith('for https://downstream.test/api/object.')
        ->and($client?->getAttributes()->toArray())->toMatchArray(['server.address' => 'downstream.test', 'url.path' => '/api/object'])
        ->and($client?->getAttributes()->has('url.full'))->toBeFalse()
        ->and(array_map(static fn (EventInterface $event): string => $event->getName(), $events))->toBe(['exception'])
        ->and($events[0]->getAttributes()->get('exception.message'))->toEndWith('for https://downstream.test/api/object.')
        ->and(json_encode([$client?->getAttributes()->toArray(), $client?->getStatus()->getDescription(), $events[0]->getAttributes()->toArray()], JSON_THROW_ON_ERROR))->not->toContain('secret');
});

it('makes the CLIENT spans of an Http::pool() fan-out siblings under the request span, each sending its own traceparent', function () {
    /** @var TracingCapstoneTestCase $this */
    Http::fake([
        'https://downstream.test/api/a' => Http::response('alpha', 200),
        'https://downstream.test/api/b' => Http::response('bravo', 503),
    ]);

    $this->getJson('/fanout')->assertStatus(200)->assertExactJson(['a' => 'alpha', 'b' => 'bravo']);

    $server = $this->spanNamed('GET /fanout');
    $clientTo = function (string $path): ?ImmutableSpan {
        foreach ($this->spans() as $span) {
            if ($span->getKind() === OtelSpanKind::KIND_CLIENT && $span->getAttributes()->get('url.path') === $path) {
                return $span;
            }
        }

        return null;
    };
    $a = $clientTo('/api/a');
    $b = $clientTo('/api/b');

    expect($server)->not->toBeNull()
        ->and($this->spans())->toHaveCount(3)
        ->and($a?->getParentSpanId())->toBe($server?->getSpanId())
        ->and($b?->getParentSpanId())->toBe($server?->getSpanId())
        ->and($a?->getTraceId())->toBe($server?->getTraceId())
        ->and($b?->getTraceId())->toBe($server?->getTraceId())
        ->and($a?->getAttributes()->get('http.response.status_code'))->toBe(200)
        ->and($a?->getStatus()->getCode())->toBe(StatusCode::STATUS_UNSET)
        ->and($b?->getAttributes()->get('http.response.status_code'))->toBe(503)
        ->and($b?->getStatus()->getCode())->toBe(StatusCode::STATUS_ERROR);

    Http::assertSentCount(2);
    Http::assertSent(static fn (Request $request): bool => $request->url() === 'https://downstream.test/api/a'
        && $request->hasHeader('traceparent', '00-'.$server?->getTraceId().'-'.$a?->getSpanId().'-01'));
    Http::assertSent(static fn (Request $request): bool => $request->url() === 'https://downstream.test/api/b'
        && $request->hasHeader('traceparent', '00-'.$server?->getTraceId().'-'.$b?->getSpanId().'-01'));
});
