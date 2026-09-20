<?php

declare(strict_types=1);

namespace Firefly\Observability\Web;

use Firefly\Observability\Tracing\Span;
use Firefly\Observability\Tracing\SpanKind;
use Firefly\Observability\Tracing\SpanStatus;
use Firefly\Observability\Tracing\Tracer;
use Firefly\Observability\Tracing\W3CTraceContextPropagator;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * A Guzzle middleware that gives every Laravel Http client request a CLIENT span and a traceparent — Spring's
 * RestClient/WebClient observation, on the transport LaraFly already has. Installed once, on the Http factory,
 * by HttpClientTracingPass; a Guzzle middleware is `callable(callable $handler): callable(Request, array):
 * PromiseInterface`, and this class is exactly that shape.
 *
 * WHAT IS ON THE SPAN, and what is deliberately not. Attributes follow the OTel HTTP client semantic
 * conventions — http.request.method, url.scheme, server.address, server.port, url.path,
 * http.response.status_code — and NOT url.full: a full URL carries the query string, and `?token=`,
 * `?api_key=` and signed URLs live there. The same rule keeps the query out of /actuator/httpexchanges. The
 * span is named by the method alone (the conventions' `{method}`: a client has no route template, and a path
 * with ids in it would be an unbounded name).
 *
 * WHEN IT ENDS. In the promise's then(): a fulfilled response sets the status code (ERROR at 4xx/5xx — the
 * client-side convention, unlike a server span's 5xx-only rule) and ends; a rejection records the reason as
 * the exception, marks ERROR, ends, and re-rejects untouched so RequestException handling downstream is
 * unaffected. A handler that throws synchronously (a stray-request guard, a malformed option) ends the span
 * before the throwable escapes. Laravel's own before-sending, recorder and stub handlers sit INSIDE this one
 * (global middleware is pushed first, so it is outermost), which is why Http::fake() exercises the whole path
 * and Http::recorded() sees the injected header.
 */
final class HttpClientTracingMiddleware
{
    public function __construct(
        private readonly Tracer $tracer,
        private readonly W3CTraceContextPropagator $propagator,
    ) {}

    /**
     * @param  callable(RequestInterface, array<mixed>): PromiseInterface  $handler
     * @return callable(RequestInterface, array<mixed>): PromiseInterface
     */
    public function __invoke(callable $handler): callable
    {
        return function (RequestInterface $request, array $options) use ($handler): PromiseInterface {
            $uri = $request->getUri();
            $span = $this->tracer->startSpan($request->getMethod(), SpanKind::Client, [
                'http.request.method' => $request->getMethod(),
                'url.scheme' => $uri->getScheme(),
                'server.address' => $uri->getHost(),
                'server.port' => $uri->getPort() ?? ($uri->getScheme() === 'https' ? 443 : 80),
                'url.path' => $uri->getPath() === '' ? '/' : $uri->getPath(),
            ]);

            foreach ($this->propagator->inject($span->context()) as $name => $value) {
                $request = $request->withHeader($name, $value);
            }

            try {
                $promise = $handler($request, $options);
            } catch (Throwable $e) {
                $this->fail($span, $e);

                throw $e;
            }

            return $promise->then(
                function (ResponseInterface $response) use ($span): ResponseInterface {
                    $span->setAttribute('http.response.status_code', $response->getStatusCode());
                    if ($response->getStatusCode() >= 400) {
                        $span->setStatus(SpanStatus::Error);
                    }
                    $span->end();

                    return $response;
                },
                function (mixed $reason) use ($span): PromiseInterface {
                    if ($reason instanceof Throwable) {
                        $this->fail($span, $reason);
                    } else {
                        $span->setStatus(SpanStatus::Error);
                        $span->end();
                    }

                    return Create::rejectionFor($reason);
                },
            );
        };
    }

    private function fail(Span $span, Throwable $e): void
    {
        $span->recordException($e)->setStatus(SpanStatus::Error, $e->getMessage());
        $span->end();
    }
}
