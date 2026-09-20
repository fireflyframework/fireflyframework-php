<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Observability\Tracing\NoOpTracer;
use Firefly\Observability\Tracing\SpanKind;
use Firefly\Observability\Tracing\SpanStatus;
use Firefly\Observability\Tracing\Tracer;
use Firefly\Observability\Tracing\W3CTraceContextPropagator;
use Firefly\Observability\Web\TracingFilter;
use Firefly\Testing\Double\RecordingTracer;
use Firefly\Web\Filter\CorrelationIdFilter;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Route;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Tested against a BARE Request with no booted application, like MetricsFilterTest and HttpExchangeFilterTest:
 * the ids land on Request::$attributes (always) and in Laravel Context (only when a facade root exists), so the
 * Context half is proven by the capstone and everything else here.
 *
 * @param  array<string, mixed>  $firefly  dot-keyed firefly.* config
 */
function tracingFilter(Tracer $tracer, array $firefly = []): TracingFilter
{
    return new TracingFilter($tracer, new W3CTraceContextPropagator, new Config(new ConfigRepository($firefly)));
}

function routedRequest(string $path, string $template): Request
{
    $request = Request::create($path, 'GET');
    $route = new Route('GET', $template, []);
    $route->bind($request);
    $request->setRouteResolver(fn () => $route);

    return $request;
}

it('starts a SERVER span, names it by the route template once the router has matched, and ends it', function () {
    $tracer = new RecordingTracer;
    $request = routedRequest('/orders/42', 'orders/{id}');
    $request->headers->set(CorrelationIdFilter::HEADER, 'corr-1');

    $response = tracingFilter($tracer)->handle($request, fn () => new Response('ok', 201));

    $span = $tracer->find('GET /orders/{id}');
    expect($response)->toBeInstanceOf(Response::class)
        ->and($span)->not->toBeNull()
        ->and($span?->kind)->toBe(SpanKind::Server)
        ->and($span?->ended)->toBeTrue()
        ->and($span?->attributes)->toMatchArray([
            'http.request.method' => 'GET',
            'url.path' => '/orders/42',
            'http.route' => '/orders/{id}',
            'http.response.status_code' => 201,
            'firefly.correlation_id' => 'corr-1',
        ])
        ->and($span?->status)->toBe(SpanStatus::Unset)
        ->and($tracer->currentSpan())->toBeNull();
});

it('continues an inbound traceparent and publishes the ids as request attributes', function () {
    $tracer = new RecordingTracer;
    $request = Request::create('/orders/42', 'GET', server: [
        'HTTP_TRACEPARENT' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01',
        'HTTP_TRACESTATE' => 'congo=t61rcWkgMzE',
    ]);

    tracingFilter($tracer)->handle($request, fn () => new Response('ok'));

    $span = $tracer->recorded()[0];
    expect($span->traceId())->toBe('4bf92f3577b34da6a3ce929d0e0e4736')
        ->and($span->parent?->spanId)->toBe('00f067aa0ba902b7')
        ->and($span->context()->traceState)->toBe('congo=t61rcWkgMzE')
        // No matched route: the name stays the method, as the OTel HTTP semantic conventions ask.
        ->and($span->name)->toBe('GET')
        ->and($request->attributes->get(TracingFilter::CONTEXT_TRACE_ID))->toBe('4bf92f3577b34da6a3ce929d0e0e4736')
        ->and($request->attributes->get(TracingFilter::CONTEXT_SPAN_ID))->toBe($span->spanId());
});

it('marks a 5xx and a thrown request as ERROR, records the exception, and rethrows', function () {
    $tracer = new RecordingTracer;

    tracingFilter($tracer)->handle(Request::create('/down', 'GET'), fn () => new Response('nope', 503));

    expect(fn () => tracingFilter($tracer)->handle(Request::create('/boom', 'GET'), function (): never {
        throw new RuntimeException('boom');
    }))->toThrow(RuntimeException::class, 'boom');

    [$down, $boom] = $tracer->recorded();
    expect($down->status)->toBe(SpanStatus::Error)
        ->and($down->attributes['http.response.status_code'])->toBe(503)
        ->and($boom->status)->toBe(SpanStatus::Error)
        ->and($boom->exception?->getMessage())->toBe('boom')
        ->and($boom->attributes['http.response.status_code'])->toBe(500)
        ->and($boom->ended)->toBeTrue();
});

/**
 * Through the real HTTP kernel this filter never sees a Throwable: Illuminate\Routing\Pipeline renders the
 * exception at the innermost slice and hands the outer middleware a finished 500 with the throwable attached
 * (ResponseTrait::$exception). The span must still carry the exception event and its message.
 */
it('records the exception Laravel already rendered into a 500 response, with its message as the status description', function () {
    $tracer = new RecordingTracer;
    $rendered = (new JsonResponse(['title' => 'Internal Server Error'], 500))->withException(new RuntimeException('rendered boom'));

    $response = tracingFilter($tracer)->handle(Request::create('/boom', 'GET'), fn () => $rendered);

    $span = $tracer->recorded()[0];
    expect($response)->toBe($rendered)
        ->and($span->status)->toBe(SpanStatus::Error)
        ->and($span->statusDescription)->toBe('rendered boom')
        ->and($span->exception?->getMessage())->toBe('rendered boom')
        ->and($span->attributes['http.response.status_code'])->toBe(500)
        ->and(array_column($span->events, 'name'))->toContain('exception')
        ->and($span->ended)->toBeTrue();
});

/**
 * The same pipeline attaches the throwable to a 4xx it rendered (abort(404), a ValidationException, an
 * AuthenticationException...) — the bulk of a real application's non-2xx traffic. The OTel HTTP semantic
 * conventions say a 4xx MUST leave a SERVER span's status Unset, and MetricsFilter tags the same request
 * outcome=CLIENT_ERROR exception=none, so neither Illuminate response class may turn one into an errored trace.
 */
it('leaves a rendered 4xx Unset and records no exception event', function () {
    $tracer = new RecordingTracer;
    $notFound = (new Response('Not Found', 404))->withException(new NotFoundHttpException('gone'));
    $invalid = (new JsonResponse(['title' => 'Unprocessable Entity'], 422))->withException(new UnprocessableEntityHttpException('invalid'));

    tracingFilter($tracer)->handle(Request::create('/missing', 'GET'), fn () => $notFound);
    tracingFilter($tracer)->handle(Request::create('/orders', 'POST'), fn () => $invalid);

    [$missing, $orders] = $tracer->recorded();
    expect($missing->status)->toBe(SpanStatus::Unset)
        ->and($missing->statusDescription)->toBe('')
        ->and($missing->exception)->toBeNull()
        ->and(array_column($missing->events, 'name'))->not->toContain('exception')
        ->and($missing->attributes['http.response.status_code'])->toBe(404)
        ->and($missing->ended)->toBeTrue()
        ->and($orders->status)->toBe(SpanStatus::Unset)
        ->and($orders->exception)->toBeNull()
        ->and(array_column($orders->events, 'name'))->not->toContain('exception')
        ->and($orders->attributes['http.response.status_code'])->toBe(422)
        ->and($orders->ended)->toBeTrue();
});

it('does not trace management traffic by default, and an explicit exclude list replaces that default', function () {
    $tracer = new RecordingTracer;
    $filter = tracingFilter($tracer);

    $filter->handle(Request::create('/actuator/health', 'GET'), fn () => new Response('ok'));
    $filter->handle(Request::create('/actuator', 'GET'), fn () => new Response('ok'));
    expect($tracer->recorded())->toBe([]);

    $relocated = tracingFilter($tracer, ['firefly' => ['management' => ['endpoints' => ['web' => ['base-path' => '/ops']]]]]);
    $relocated->handle(Request::create('/ops/health', 'GET'), fn () => new Response('ok'));
    $relocated->handle(Request::create('/actuator/health', 'GET'), fn () => new Response('ok'));
    expect($tracer->spans)->toBe(['GET']);

    $explicit = tracingFilter($tracer, ['firefly' => ['observability' => ['tracing' => ['http-server' => ['exclude' => ['internal/*']]]]]]);
    $explicit->handle(Request::create('/internal/ping', 'GET'), fn () => new Response('ok'));
    $explicit->handle(Request::create('/actuator/health', 'GET'), fn () => new Response('ok'));
    expect($tracer->spans)->toBe(['GET', 'GET']);
});

it('publishes no ids for a NoOp tracer and still runs the chain', function () {
    $request = Request::create('/orders/42', 'GET', server: ['HTTP_TRACEPARENT' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01']);

    $response = tracingFilter(new NoOpTracer)->handle($request, fn () => new Response('ok'));

    expect($response)->toBeInstanceOf(Response::class)
        ->and($response instanceof Response ? $response->getContent() : null)->toBe('ok')
        ->and($request->attributes->has(TracingFilter::CONTEXT_TRACE_ID))->toBeFalse();
});
