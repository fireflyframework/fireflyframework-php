<?php

declare(strict_types=1);

namespace Firefly\Observability\Web;

use Closure;
use Firefly\Config\Config;
use Firefly\Container\Attributes\Component;
use Firefly\Container\Attributes\Lazy;
use Firefly\Container\Attributes\Order;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Firefly\Observability\Tracing\Span;
use Firefly\Observability\Tracing\SpanKind;
use Firefly\Observability\Tracing\SpanStatus;
use Firefly\Observability\Tracing\Tracer;
use Firefly\Observability\Tracing\W3CTraceContextPropagator;
use Firefly\Web\Filter\CorrelationIdFilter;
use Firefly\Web\Filter\OncePerRequestFilter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as IlluminateResponse;
use Illuminate\Log\Context\Repository as ContextRepository;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * One SERVER span per request — Spring's ServerHttpObservationFilter, as a WebFilter.
 *
 * ORDER. #[Order(-110)] makes this the OUTERMOST discovered filter: FilterChainRegistrar prepends the two
 * framework filters (RequestContextFilter, CorrelationIdFilter) ahead of every discovered one, so this runs
 * right after the correlation id exists and wraps HttpExchangeFilter/MetricsFilter (-100). The span therefore
 * times the same chain the metrics timer does, and is still ACTIVE when HttpExchangeFilter records on the way
 * out — which is how the exchange row gets its traceId.
 *
 * NAMING. Global middleware runs before the router matches, so the span starts named by the method alone and
 * is renamed `GET /orders/{id}` once $next returns and $request->route() is known — the OTel HTTP semantic
 * conventions' `{method} {http.route}`, and the same template-not-path rule MetricsFilter's uri tag follows
 * (bounded cardinality; the raw path is an attribute, url.path, never the name).
 *
 * ERRORS. Two paths, because Laravel has two. Illuminate\Routing\Pipeline catches whatever the route (or an
 * inner middleware) threw, has the ExceptionHandler render it, and hands the OUTER slices a finished response
 * with the throwable attached (ResponseTrait::$exception, set by withException()) — so through the real HTTP
 * kernel this filter sees a 500 Response, never a Throwable. The rendered exception is recorded off the
 * response the way Spring's ServerHttpObservationFilter reads the handler's exception attribute. The catch
 * block is still needed for a throwable that escapes the pipeline (no ExceptionHandler bound, a bare Request
 * in a unit test): the span is marked ERROR either way and never left open.
 *
 * PROPAGATION. An inbound traceparent/tracestate is continued as a remote parent; nothing is written on the
 * response (W3C defines no response header). The ids are published twice: to Laravel Context
 * (firefly.trace_id / firefly.span_id — what TraceContextLogProcessor and any application code read) and to
 * Request::$attributes under the same keys (what HttpExchangeFilter reads, because a bare Request is testable
 * and the Context facade is not). A NoOp tracer's span has an invalid context and publishes nothing.
 *
 * GATING. Two properties, AND-ed: the tracing master gate (off by default) and this instrumentation's own
 * switch (on by default under the master). Gated on properties, not on the Tracer bean, for the order-safety
 * reason MetricsFilter documents. #[Lazy] for the reason it documents too: Tracer is a #[Bean] resolved at
 * EagerSingletons, the phase a non-lazy #[Component] would be built at.
 */
#[Component]
#[Order(-110)]
#[ConditionalOnProperty(name: 'firefly.observability.tracing.enabled', havingValue: 'true')]
#[ConditionalOnProperty(name: 'firefly.observability.tracing.http-server.enabled', havingValue: 'true', matchIfMissing: true)]
#[Lazy]
final class TracingFilter extends OncePerRequestFilter
{
    public const string CONTEXT_TRACE_ID = 'firefly.trace_id';

    public const string CONTEXT_SPAN_ID = 'firefly.span_id';

    private const string EXCLUDE_KEY = 'firefly.observability.tracing.http-server.exclude';

    private const string BASE_PATH_KEY = 'firefly.management.endpoints.web.base-path';

    /** @var list<string> */
    private readonly array $excludes;

    public function __construct(
        private readonly Tracer $tracer,
        private readonly W3CTraceContextPropagator $propagator,
        Config $config,
    ) {
        $this->excludes = $this->configuredExcludes($config);
    }

    /**
     * The same default and the same replace-not-add rule as HttpExchangeFilter::configuredExcludes(): a
     * dashboard polls, and a trace per poll is noise a backend bills for.
     *
     * @return list<string>
     */
    private function configuredExcludes(Config $config): array
    {
        if (! $config->has(self::EXCLUDE_KEY)) {
            $base = trim($config->string(self::BASE_PATH_KEY, '/actuator'), '/');
            $base = $base === '' ? 'actuator' : $base;

            return [$base, $base.'/*'];
        }

        $patterns = [];
        foreach ($config->array(self::EXCLUDE_KEY, []) as $pattern) {
            if (is_string($pattern) && $pattern !== '') {
                $patterns[] = trim($pattern, '/');
            }
        }

        return $patterns;
    }

    /** @return list<string> */
    protected function excludes(): array
    {
        return $this->excludes;
    }

    protected function doFilter(Request $request, Closure $next): mixed
    {
        $span = $this->tracer->startSpan($request->getMethod(), SpanKind::Server, [
            'http.request.method' => $request->getMethod(),
            'url.path' => $request->getPathInfo(),
            'url.scheme' => $request->getScheme(),
            'server.address' => $request->getHost(),
            'firefly.correlation_id' => (string) $request->headers->get(CorrelationIdFilter::HEADER, ''),
        ], $this->propagator->extract($request->headers->all()));

        $this->publish($request, $span);

        try {
            $response = $next($request);
            $this->finish($span, $request, $response instanceof Response ? $response->getStatusCode() : 200, $this->renderedException($response));

            return $response;
        } catch (Throwable $e) {
            $this->finish($span, $request, 500, $e);

            throw $e;
        }
    }

    /**
     * Names the span by the matched route, stamps the status code, sets the status ONCE (an exception carries
     * its message as the description; a bare 5xx carries none) and ends the span.
     */
    private function finish(Span $span, Request $request, int $status, ?Throwable $exception): void
    {
        $route = $request->route();
        if ($route !== null) {
            $template = '/'.ltrim((string) $route->uri(), '/');
            $span->setAttribute('http.route', $template)->updateName($request->getMethod().' '.$template);
        }

        $span->setAttribute('http.response.status_code', $status);

        if ($exception !== null) {
            $span->recordException($exception)->setStatus(SpanStatus::Error, $exception->getMessage());
        } elseif ($status >= 500) {
            $span->setStatus(SpanStatus::Error);
        }

        $span->end();
    }

    /**
     * The throwable Laravel's routing pipeline already rendered into this response, if any. Only the two
     * Illuminate response classes carry ResponseTrait; a Symfony response or a non-response return has none.
     */
    private function renderedException(mixed $response): ?Throwable
    {
        if ($response instanceof IlluminateResponse || $response instanceof JsonResponse) {
            return $response->exception;
        }

        return null;
    }

    private function publish(Request $request, Span $span): void
    {
        if (! $span->context()->isValid()) {
            return;
        }

        $request->attributes->set(self::CONTEXT_TRACE_ID, $span->traceId());
        $request->attributes->set(self::CONTEXT_SPAN_ID, $span->spanId());

        $context = $this->context();
        $context?->add(self::CONTEXT_TRACE_ID, $span->traceId());
        $context?->add(self::CONTEXT_SPAN_ID, $span->spanId());
    }

    /** The same guarded Context access CorrelationIdFilter::context() uses: no facade root, no Context write. */
    private function context(): ?ContextRepository
    {
        $app = Facade::getFacadeApplication();

        if ($app === null || ! $app->bound(ContextRepository::class)) {
            return null;
        }

        /** @var ContextRepository $repository */
        $repository = $app->make(ContextRepository::class);

        return $repository;
    }
}
