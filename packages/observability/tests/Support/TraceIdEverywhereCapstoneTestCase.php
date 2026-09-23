<?php

declare(strict_types=1);

namespace Firefly\Observability\Tests\Support;

use Illuminate\Routing\Router;
use RuntimeException;

/**
 * The whole chain a failing request goes through, with a REAL tracer behind it, so the claim "the trace id
 * is the trace id" is proved where it has to hold: through the HTTP kernel, not against a hand-built Request.
 *
 * WHY THIS BOOT. ObservabilityTracingCapstoneTestCase already turns `firefly.observability.tracing.enabled`
 * on at BOOT time (the phase TracingFilter's #[ConditionalOnProperty] is evaluated in, so it cannot be done
 * from a test body) with the OpenTelemetry SDK's own TracerProvider behind the Tracer port and the exporter
 * set to `none`. That last part is exactly what this suite wants: a span's context is valid whether or not
 * anything exports it, so the request gets a real W3C trace id and no span leaves the process.
 *
 * WHAT IS ADDED. One route that throws, and the error page switched on with `json-paths` left at the API
 * default — so the SAME url answers a browser with the HTML page and an `Accept: application/json` client
 * with the problem document, which is the only way to assert that both surfaces publish one id.
 */
class TraceIdEverywhereCapstoneTestCase extends ObservabilityTracingCapstoneTestCase
{
    protected function configOverrides(): array
    {
        return [
            ...parent::configOverrides(),
            'firefly.web.error-page.enabled' => true,
            // `/boom` is deliberately NOT under `api/*`: the path must stay eligible for the HTML page so a
            // browser's Accept header decides the rendering, exactly as it does in an application.
            'firefly.web.error-page.json-paths' => 'api/*',
        ];
    }

    /**
     * The parameter stays untyped for the reason ObservabilityCapstoneTestCase::defineRoutes() gives:
     * Testbench declares it untyped, and narrowing a parameter in an override is an LSP violation PHP
     * rejects outright.
     *
     * @param  Router  $router
     */
    protected function defineRoutes($router): void
    {
        parent::defineRoutes($router);

        $router->get('/boom', static function (): never {
            throw new RuntimeException('boom');
        });
    }
}
