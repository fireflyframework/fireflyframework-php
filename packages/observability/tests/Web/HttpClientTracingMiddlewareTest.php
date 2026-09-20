<?php

declare(strict_types=1);

use Firefly\Observability\Tracing\SpanKind;
use Firefly\Observability\Tracing\SpanStatus;
use Firefly\Observability\Tracing\W3CTraceContextPropagator;
use Firefly\Observability\Web\HttpClientTracingMiddleware;
use Firefly\Testing\Double\RecordingTracer;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\RejectedPromise;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Request as ClientRequest;
use Psr\Http\Message\RequestInterface;

/**
 * The middleware is a plain Guzzle middleware, so it is tested with a hand-rolled handler: what it receives is
 * what the wire would see. `->wait()` runs the promise's then() chain synchronously, as PendingRequest::send()
 * does.
 *
 * @param  callable(RequestInterface, array<mixed>): PromiseInterface  $handler
 */
function traced(RecordingTracer $tracer, callable $handler, RequestInterface $request): mixed
{
    $middleware = new HttpClientTracingMiddleware($tracer, new W3CTraceContextPropagator);

    return $middleware($handler)($request, [])->wait();
}

it('starts a CLIENT span under the current span, injects traceparent, and records the status code', function () {
    $tracer = new RecordingTracer;
    $server = $tracer->startSpan('GET /orders/{id}', SpanKind::Server);
    $seen = null;

    $response = traced($tracer, function (RequestInterface $request) use (&$seen): PromiseInterface {
        $seen = $request;

        return new FulfilledPromise(new Response(201));
    }, new Request('POST', 'https://payments.test:8443/charges?token=secret'));

    $client = $tracer->find('POST');
    expect($response)->toBeInstanceOf(Response::class)
        ->and($client?->kind)->toBe(SpanKind::Client)
        ->and($client?->parent?->spanId)->toBe($server->spanId())
        ->and($client?->traceId())->toBe($server->traceId())
        ->and($client?->attributes)->toMatchArray([
            'http.request.method' => 'POST',
            'url.scheme' => 'https',
            'server.address' => 'payments.test',
            'server.port' => 8443,
            'url.path' => '/charges',
            'http.response.status_code' => 201,
        ])
        ->and($client?->attributes)->not->toHaveKey('url.full')
        ->and($client?->ended)->toBeTrue()
        ->and($client?->status)->toBe(SpanStatus::Unset)
        ->and($seen?->getHeaderLine('traceparent'))->toBe('00-'.$server->traceId().'-'.$client?->spanId().'-01')
        ->and($tracer->currentSpan())->toBe($server);
});

it('marks a 4xx/5xx response and a rejected promise as ERROR, and re-rejects', function () {
    $tracer = new RecordingTracer;

    traced($tracer, fn (): PromiseInterface => new FulfilledPromise(new Response(503)), new Request('GET', 'http://down.test/'));

    $failure = new ConnectException('refused', new Request('GET', 'http://gone.test/'));
    expect(fn () => traced($tracer, fn (): PromiseInterface => new RejectedPromise($failure), new Request('GET', 'http://gone.test/')))
        ->toThrow(ConnectException::class, 'refused');

    [$down, $gone] = $tracer->recorded();
    expect($down->status)->toBe(SpanStatus::Error)
        ->and($down->attributes['http.response.status_code'])->toBe(503)
        ->and($down->attributes['server.port'])->toBe(80)
        ->and($down->attributes['url.path'])->toBe('/')
        ->and($gone->status)->toBe(SpanStatus::Error)
        ->and($gone->exception)->toBe($failure)
        ->and($gone->ended)->toBeTrue();
});

it('ends the span and rethrows when the handler itself throws', function () {
    $tracer = new RecordingTracer;

    expect(fn () => traced($tracer, function (): never {
        throw new RuntimeException('handler blew up');
    }, new Request('GET', 'https://x.test/')))->toThrow(RuntimeException::class);

    expect($tracer->recorded()[0]->ended)->toBeTrue()
        ->and($tracer->recorded()[0]->status)->toBe(SpanStatus::Error);
});

it('keeps the CLIENT span current only while the handler runs, so a request started before the last one settled is a sibling', function () {
    $tracer = new RecordingTracer;
    $server = $tracer->startSpan('GET /fanout', SpanKind::Server);
    $middleware = new HttpClientTracingMiddleware($tracer, new W3CTraceContextPropagator);

    // A handler that answers with a promise nobody settles yet — what a real transport does while the
    // response is still on the wire.
    /** @var list<Promise> $pending */
    $pending = [];
    $traced = $middleware(function () use (&$pending): PromiseInterface {
        return $pending[] = new Promise;
    });

    $first = $traced(new Request('GET', 'https://a.test/one'), []);
    expect($tracer->currentSpan())->toBe($server);

    $second = $traced(new Request('GET', 'https://b.test/two'), []);
    expect($tracer->currentSpan())->toBe($server);

    // Settled out of order, as concurrent responses arrive.
    $pending[1]->resolve(new Response(200));
    $pending[0]->resolve(new Response(502));
    $second->wait();
    $first->wait();

    [$a, $b] = $tracer->ofKind(SpanKind::Client);
    expect($a->parent?->spanId)->toBe($server->spanId())
        ->and($b->parent?->spanId)->toBe($server->spanId())
        ->and($a->attributes['http.response.status_code'])->toBe(502)
        ->and($a->status)->toBe(SpanStatus::Error)
        ->and($a->ended)->toBeTrue()
        ->and($b->attributes['http.response.status_code'])->toBe(200)
        ->and($b->ended)->toBeTrue()
        ->and($tracer->currentSpan())->toBe($server);
});

it('gives every request of an Http::pool() its own CLIENT span under the current span, each with its own traceparent', function () {
    $tracer = new RecordingTracer;
    $server = $tracer->startSpan('GET /fanout', SpanKind::Server);

    // The real Laravel factory: pool() builds every request's promise (running this middleware) before it
    // waits on any of them, which is the shape that used to chain the spans parent -> child.
    $factory = new HttpFactory;
    $factory->globalMiddleware(new HttpClientTracingMiddleware($tracer, new W3CTraceContextPropagator));
    $factory->fake([
        'https://a.test/*' => HttpFactory::response('A'),
        'https://b.test/*' => HttpFactory::response('B', 404),
    ]);

    $responses = $factory->pool(static fn (Pool $pool): array => [
        $pool->as('a')->get('https://a.test/one'),
        $pool->as('b')->get('https://b.test/two'),
    ]);

    expect($responses)->toHaveKeys(['a', 'b'])
        ->and($tracer->currentSpan())->toBe($server)
        ->and($tracer->ofKind(SpanKind::Client))->toHaveCount(2);

    [$a, $b] = $tracer->ofKind(SpanKind::Client);
    expect($a->attributes['server.address'])->toBe('a.test')
        ->and($a->parent?->spanId)->toBe($server->spanId())
        ->and($a->attributes['http.response.status_code'])->toBe(200)
        ->and($a->ended)->toBeTrue()
        ->and($b->attributes['server.address'])->toBe('b.test')
        ->and($b->parent?->spanId)->toBe($server->spanId())
        ->and($b->attributes['http.response.status_code'])->toBe(404)
        ->and($b->status)->toBe(SpanStatus::Error)
        ->and($b->ended)->toBeTrue();

    $factory->assertSentCount(2);
    $factory->assertSent(static fn (ClientRequest $request): bool => $request->url() === 'https://a.test/one'
        && $request->hasHeader('traceparent', '00-'.$server->traceId().'-'.$a->spanId().'-01'));
    $factory->assertSent(static fn (ClientRequest $request): bool => $request->url() === 'https://b.test/two'
        && $request->hasHeader('traceparent', '00-'.$server->traceId().'-'.$b->spanId().'-01'));
});
