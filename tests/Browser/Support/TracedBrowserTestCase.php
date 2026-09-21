<?php

declare(strict_types=1);

namespace Firefly\Tests\Browser\Support;

/**
 * The skeleton with the observability wave switched on the way an operator would switch it on: tracing with
 * the in-process OpenTelemetry tracer and no exporter (spans are recorded for their ids and propagation and
 * exported nowhere — the trace id column and the log fields are what a browser can observe), histogram
 * buckets on every timer (so the HTTP server timer scrapes as a histogram), HTTP exchanges recorded, and the
 * two management endpoints the scenarios read added to the exposure list. The dashboard's own paths and the
 * actuator's are excluded from the exchange ring, as the admin capstone does, so the traffic page lists the
 * visits and never the request that rendered it.
 *
 * Every request the plugin serves runs inside a fiber; the tracer initialises the fiber's OTel context itself
 * (OpenTelemetryTracer::ensureFiberContext()), which is what makes tracing possible here at all.
 */
abstract class TracedBrowserTestCase extends BrowserTestCase
{
    /** @return array<string, mixed> */
    protected function configOverrides(): array
    {
        return [
            ...parent::configOverrides(),
            'firefly.observability.tracing.enabled' => true,
            'firefly.observability.tracing.exporter' => 'none',
            'firefly.observability.metrics.distribution.buckets' => [0.05, 0.5, 5],
            'firefly.observability.httpexchanges.enabled' => true,
            'firefly.observability.httpexchanges.exclude' => ['actuator', 'actuator/*', 'firefly', 'firefly/*'],
            'firefly.management.endpoints.web.exposure.include' => 'health,info,prometheus,metrics,httpexchanges',
        ];
    }
}
