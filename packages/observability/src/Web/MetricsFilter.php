<?php

declare(strict_types=1);

namespace Firefly\Observability\Web;

use Closure;
use Firefly\Container\Attributes\Component;
use Firefly\Container\Attributes\Lazy;
use Firefly\Container\Attributes\Order;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Firefly\Observability\Metrics\MetricsRecorder;
use Firefly\Web\Filter\OncePerRequestFilter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Auto-instruments every HTTP request as http_server_requests_seconds{method,uri,status,outcome,exception}. A
 * #[Component] WebFilter discovered by web's FilterChainRegistrar (no web edit). #[Order(-100)] makes it outer among
 * discovered filters so it times the whole inner chain (security/controllers). Gated on
 * firefly.observability.metrics.enabled — the SAME property that gates the MeterRegistry bean itself — so it only
 * registers when metrics are enabled, order-independent of MeterRegistry's own registration (a
 * #[ConditionalOnBean(MeterRegistry::class)] would instead evaluate before ObservabilityAutoConfiguration's own
 * #[Order(500)] binds it, wrongly dropping this filter even when metrics ARE enabled — see
 * PrometheusEndpoint/MetricsEndpoint's docblocks for the identical reasoning). On a thrown request it records
 * SERVER_ERROR + the exception class, then rethrows so ProblemDetailsRenderer still handles the error.
 *
 * #[Lazy] is REQUIRED, not decorative — the same eager-resolution hazard as MetricsEndpoint/PrometheusEndpoint
 * (T14): ObservabilityAutoConfiguration (T17) binds MetricsRecorder as a #[Bean] resolved at
 * BootPhase::EagerSingletons (900) — the SAME phase EagerSingletonsPass would otherwise eagerly build this
 * #[Component] at. Without #[Lazy] the resolution order between MetricsFilter and MetricsRecorder — two
 * phase-900 candidates — would be unreliable, and a plain (non-lazy) MetricsFilter could be constructed before
 * MetricsRecorder exists in the container, crashing boot. #[Lazy] excludes it from the eager pass; Laravel then
 * resolves it lazily, per-request, the first time the middleware pipeline actually invokes it — strictly after
 * EagerSingletons has already bound MetricsRecorder.
 */
#[Component]
#[Order(-100)]
#[ConditionalOnProperty(name: 'firefly.observability.metrics.enabled', havingValue: 'true', matchIfMissing: true)]
#[Lazy]
final class MetricsFilter extends OncePerRequestFilter
{
    /**
     * Bounded sentinel used as the `uri` tag when the request has no matched route (e.g. any 404). The raw path
     * (Request::getPathInfo()) must NEVER be used as a tag value for an unmatched route: it is attacker/crawler
     * controlled and unbounded, so tagging with it would create one metric series per distinct garbage path hit.
     * Micrometer's own HTTP server instrumentation uses an identical bounded sentinel for exactly this case. This
     * is harmless under PHP-FPM (a fresh, per-request registry is discarded when the request ends) but CRITICAL
     * under Octane, where the MeterRegistry is a process-lifetime singleton — an unbounded uri tag there becomes
     * an unbounded, persistent memory-growth vector.
     */
    private const string UNMATCHED_ROUTE_URI = 'UNKNOWN';

    public function __construct(private readonly MetricsRecorder $recorder) {}

    protected function doFilter(Request $request, Closure $next): mixed
    {
        $start = microtime(true);

        try {
            $response = $next($request);
            $status = $response instanceof Response ? $response->getStatusCode() : 200;
            $this->record($request, $start, $status, $this->outcome($status), 'none');

            return $response;
        } catch (Throwable $e) {
            $this->record($request, $start, 500, 'SERVER_ERROR', $e::class);

            throw $e;
        }
    }

    private function record(Request $request, float $start, int $status, string $outcome, string $exception): void
    {
        $this->recorder->record('http_server_requests_seconds', [
            'method' => $request->getMethod(),
            'uri' => $request->route() !== null ? '/'.ltrim((string) $request->route()->uri(), '/') : self::UNMATCHED_ROUTE_URI,
            'status' => (string) $status,
            'outcome' => $outcome,
            'exception' => $this->shortName($exception),
        ], microtime(true) - $start);
    }

    private function outcome(int $status): string
    {
        return match (true) {
            $status >= 500 => 'SERVER_ERROR',
            $status >= 400 => 'CLIENT_ERROR',
            $status >= 300 => 'REDIRECTION',
            $status >= 200 => 'SUCCESS',
            default => 'UNKNOWN',
        };
    }

    private function shortName(string $class): string
    {
        if ($class === 'none') {
            return 'none';
        }

        return ($pos = strrpos($class, '\\')) !== false ? substr($class, $pos + 1) : $class;
    }
}
