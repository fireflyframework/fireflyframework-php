<?php

declare(strict_types=1);

namespace Firefly\Observability\Web;

use Closure;
use Firefly\Config\Config;
use Firefly\Container\Attributes\Component;
use Firefly\Container\Attributes\Lazy;
use Firefly\Container\Attributes\Order;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Firefly\Observability\HttpExchanges\HeaderMasker;
use Firefly\Observability\HttpExchanges\HttpExchange;
use Firefly\Observability\HttpExchanges\HttpExchangeRecorder;
use Firefly\Web\Filter\CorrelationIdFilter;
use Firefly\Web\Filter\OncePerRequestFilter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Feeds the rolling /actuator/httpexchanges buffer: one row per request, recorded on the way out.
 *
 * Structurally this is MetricsFilter's twin — a #[Component] WebFilter discovered by web's FilterChainRegistrar
 * (no edit to firefly/web), #[Order(-100)] so it is outermost among discovered filters and therefore times the
 * whole inner chain (security, controllers, the lot), and #[Lazy] for the same non-decorative reason spelled out
 * in MetricsFilter/MetricsEndpoint: HttpExchangeRecorder is bound by ObservabilityAutoConfiguration as a #[Bean]
 * resolved at BootPhase::EagerSingletons (900), which is the SAME phase EagerSingletonsPass would otherwise
 * eagerly construct this #[Component] at, and the resolution order between two phase-900 candidates is not
 * something to bet a boot on. #[Lazy] takes it out of the eager pass; Laravel builds it per-request when the
 * middleware pipeline first reaches it, strictly after the recorder exists.
 *
 * MetricsFilter also declares #[Order(-100)]; FilterChainRegistrar breaks the tie with strcmp() on the class
 * name, so the order is deterministic (HttpExchangeFilter outside MetricsFilter) rather than incidental, and the
 * two agree on what they measure to within one filter's overhead.
 *
 * GATED ON ITS OWN PROPERTY, NOT ON METRICS. firefly.observability.httpexchanges.enabled (default true, via
 * matchIfMissing) is a separate switch from firefly.observability.metrics.enabled because the two features have
 * genuinely different risk profiles: metrics aggregate, this retains individual requests. An operator who is
 * happy to publish latency histograms may still want no per-request record kept anywhere, and must be able to
 * say so without losing metrics. Gating on the PROPERTY rather than #[ConditionalOnBean(HttpExchangeRecorder)]
 * is the same order-safety argument MetricsFilter documents: the bean is registered by
 * ObservabilityAutoConfiguration's own #[Order(500)] pass, which a #[ConditionalOnBean] here would be evaluated
 * before.
 */
#[Component]
#[Order(-100)]
#[ConditionalOnProperty(name: 'firefly.observability.httpexchanges.enabled', havingValue: 'true', matchIfMissing: true)]
#[Lazy]
final class HttpExchangeFilter extends OncePerRequestFilter
{
    private const HEADERS_KEY = 'firefly.observability.httpexchanges.include-headers';

    private const EXCLUDE_KEY = 'firefly.observability.httpexchanges.exclude';

    private const BASE_PATH_KEY = 'firefly.management.endpoints.web.base-path';

    /**
     * Upper bound on the recorded uri.
     *
     * Only ever reached on the unmatched-route fallback below, where the value is attacker-controlled. Without a
     * cap, a crawler hitting a 40 kB URL would put 40 kB in a ring slot — and under the cache-backed recorder,
     * into the cache store, capacity times over. 256 characters is longer than any real route template.
     */
    private const MAX_URI_LENGTH = 256;

    private readonly bool $includeHeaders;

    /** @var list<string> */
    private readonly array $excludes;

    public function __construct(private readonly HttpExchangeRecorder $recorder, Config $config)
    {
        $this->includeHeaders = $config->bool(self::HEADERS_KEY, false);
        $this->excludes = $this->configuredExcludes($config);
    }

    /**
     * Paths that are recorded by nobody.
     *
     * Defaults to the management base path (and everything under it), because a dashboard is a POLLING client:
     * left in, a panel refreshing /actuator/httpexchanges every five seconds would — on a long-lived worker,
     * which is the only place the default recorder retains anything at all — evict every genuine request from a
     * 100-row ring within minutes and then show the operator nothing but their own polling. The buffer would be
     * perfectly accurate and completely useless.
     *
     * Setting firefly.observability.httpexchanges.exclude REPLACES this default with the given glob list (an
     * empty list means record everything, including management traffic). Anyone running the admin UI will want
     * to add its base path — firefly.admin.base-path, '/firefly' by default — for exactly the same reason; it is
     * not excluded automatically because reaching into another package's configuration key to guess at its
     * mount point is the kind of hidden coupling that breaks the day someone changes it.
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
        $start = microtime(true);

        try {
            $response = $next($request);
            $this->record($request, $start, $response instanceof Response ? $response->getStatusCode() : 200);

            return $response;
        } catch (Throwable $e) {
            // A request that threw is exactly the one an operator came to the dashboard to find, so record it —
            // as the 500 the error renderer is about to produce — before rethrowing so ProblemDetailsRenderer
            // still handles it. Mirrors MetricsFilter's SERVER_ERROR path.
            $this->record($request, $start, 500);

            throw $e;
        }
    }

    /**
     * Best-effort by construction: a recording failure must never change the response.
     *
     * With the cache-backed recorder every request performs cache I/O, so a Redis blip would otherwise turn
     * every 200 in the application into a 500 — an availability incident caused entirely by the telemetry that
     * was supposed to help diagnose one. An exchange row has no effect on the response, so the only correct
     * behaviour on failure is to lose the row. (MetricsFilter deliberately does NOT carry this guard today; that
     * asymmetry is called out here so it reads as a decision about this filter rather than an oversight in the
     * other, and it is worth revisiting there for the same reason.)
     */
    private function record(Request $request, float $start, int $status): void
    {
        try {
            $this->recorder->record(new HttpExchange(
                HttpExchange::timestampFrom($start),
                $request->getMethod(),
                $this->uri($request),
                $status,
                round((microtime(true) - $start) * 1000, 3),
                $this->correlationId($request),
                $this->includeHeaders ? HeaderMasker::mask($request->headers->all()) : [],
            ));
        } catch (Throwable) {
            // Intentionally swallowed — see the docblock. The row is lost; the response is not.
        }
    }

    /**
     * The ROUTE TEMPLATE ('/users/{id}') whenever the router matched one, which is what makes a 100-row buffer
     * readable: a busy endpoint would otherwise fill the whole ring with '/users/41', '/users/42', '/users/43'
     * and an operator scrolling it would learn nothing they did not already know.
     *
     * The fallback for an unmatched route (every 404) is the raw path — NOT MetricsFilter's bounded 'UNKNOWN'
     * sentinel, and the difference is not an inconsistency. There, the value becomes a metric TAG and an
     * unbounded tag is an unbounded series count, a permanent memory-growth vector under Octane. Here the value
     * lands in a ring of fixed size, so cardinality costs nothing — and 'UNKNOWN' would delete the single most
     * useful thing this endpoint does, which is telling an operator WHICH url is 404ing.
     *
     * getPathInfo() is used rather than getRequestUri() so the QUERY STRING never reaches the buffer:
     * '?api_key=...', '?token=...' and password-reset links live there, and a recorded url with credentials in
     * it is the leak this feature is otherwise carefully designed to avoid. The result is capped at
     * MAX_URI_LENGTH.
     */
    private function uri(Request $request): string
    {
        $route = $request->route();

        $uri = $route !== null
            ? '/'.ltrim((string) $route->uri(), '/')
            : '/'.ltrim($request->getPathInfo(), '/');

        return mb_strimwidth($uri, 0, self::MAX_URI_LENGTH, '…');
    }

    /**
     * Read off the REQUEST HEADER rather than out of Context, even though CorrelationIdLogProcessor reads the
     * Context copy.
     *
     * CorrelationIdFilter writes both: it mints or accepts the id, calls Context::add('firefly.correlation_id'),
     * and sets the header back onto the request — and FilterChainRegistrar PREPENDS it ahead of every discovered
     * WebFilter, so by the time this filter runs the header is always populated. The two sources therefore carry
     * the same value, and the header is the better one to depend on: Illuminate\Support\Facades\Context throws
     * "A facade root has not been set" without a booted application, which would make this filter untestable
     * against a bare Request the way MetricsFilterTest already tests its twin. A filter whose only unit test
     * needs a full framework boot is a filter whose edge cases stop being tested.
     */
    private function correlationId(Request $request): ?string
    {
        $value = $request->headers->get(CorrelationIdFilter::HEADER);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
