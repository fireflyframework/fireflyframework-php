<?php

declare(strict_types=1);

namespace Firefly\Observability\Endpoint;

use Firefly\Actuator\Endpoint\ActuatorEndpoint;
use Firefly\Actuator\Endpoint\EndpointRequest;
use Firefly\Actuator\Endpoint\EndpointResponse;
use Firefly\Container\Attributes\Component;
use Firefly\Container\Attributes\Lazy;
use Firefly\Observability\HttpExchanges\HttpExchangeRecorder;
use Firefly\Observability\Process\RuntimeSnapshot;

/**
 * /actuator/process — the live runtime numbers of the PHP process answering this request.
 *
 * A dashboard plots these; /actuator/metrics cannot serve the same purpose, because the memory gauges
 * MeterBindingsPass registers there are read through the MeterRegistry and therefore inherit whatever storage
 * that registry uses — under the cache-backed registry they are a LAST-WRITER-WINS snapshot from some other
 * worker, which is the correct semantic for a gauge and the wrong number for "how close is the process serving
 * me right now to its memory limit". This endpoint reads the current process directly, every call, and says
 * which process it read (`pid`), so an operator can see for themselves that consecutive refreshes under PHP-FPM
 * land on different workers.
 *
 * `uptimeMs` is measured from the construction of this bean, which is the closest honest proxy PHP offers: there
 * is no portable process start time. Under a long-lived worker (Octane/RoadRunner) the bean is built once when
 * the worker boots, so this is genuine worker uptime and the number an operator wants. Under PHP-FPM the bean is
 * built during the current request's boot, so it is the age of THIS request — which, read next to `pid`, is
 * itself the clearest possible statement of the process model.
 *
 * KEPT NON-SENSITIVE. Memory figures, opcache aggregate counters, the PHP version and the SAPI name are facts
 * about the runtime, not about the application's data or its deployment layout. There is no environment, no
 * include path, no opcache script list (see RuntimeSnapshot for why that argument is passed explicitly), no
 * loaded-extension inventory (a version-by-version attack surface listing) and no configuration.
 *
 * `handle()` narrows the contract's `?EndpointResponse` to the non-nullable type — no sub-resource to 404 on, so
 * a body is always produced and PHPStan at level max would flag the nullable type as dead code (the
 * BeansEndpoint/InfoEndpoint idiom).
 *
 * #[Lazy] is REQUIRED for the reason MetricsEndpoint documents: HttpExchangeRecorder is a #[Bean] resolved at
 * BootPhase::EagerSingletons (900), the same phase this #[Component] would otherwise be eagerly built at.
 */
#[Component]
#[Lazy]
final class ProcessEndpoint implements ActuatorEndpoint
{
    private readonly float $bootedAt;

    /**
     * @param  float|null  $bootedAt  injectable purely so a test can assert a deterministic uptime; the
     *                                container leaves it null and the bean stamps its own construction time.
     */
    public function __construct(
        private readonly HttpExchangeRecorder $recorder,
        private readonly RuntimeSnapshot $runtime = new RuntimeSnapshot,
        ?float $bootedAt = null,
    ) {
        $this->bootedAt = $bootedAt ?? microtime(true);
    }

    public function endpointId(): string
    {
        return 'process';
    }

    public function enabled(): bool
    {
        return true;
    }

    public function handle(EndpointRequest $request): EndpointResponse
    {
        $pid = getmypid();

        return EndpointResponse::json([
            'pid' => $pid === false ? 0 : $pid,
            'uptimeMs' => round((microtime(true) - $this->bootedAt) * 1000, 3),
            'php' => [
                'version' => PHP_VERSION,
                'sapi' => PHP_SAPI,
            ],
            'memory' => $this->runtime->memory(),
            'opcache' => $this->runtime->opcache(),
            // The request count the framework is ALREADY keeping — the exchange recorder's monotonic counter —
            // rather than a second counter invented for this endpoint. `recorded` is every request the filter
            // has seen, cross-process when the recorder is cache-backed. It is zero when recording is switched
            // off, which is what /actuator/httpexchanges' `recording` flag is there to explain.
            //
            // How many exchanges are CURRENTLY BUFFERED is deliberately not reported here even though it would
            // read naturally alongside `capacity`: answering it means calling exchanges(), which on the
            // cache-backed recorder is a capacity-wide multi-get. This is the endpoint a dashboard POLLS to plot
            // memory over time, so it must stay O(1) per call — a memory chart that issues a hundred cache reads
            // per data point is a load generator, not a monitor. /actuator/httpexchanges reports `count`.
            'requests' => [
                'recorded' => $this->recorder->recorded(),
                'capacity' => $this->recorder->capacity(),
            ],
        ]);
    }
}
