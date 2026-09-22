<?php

declare(strict_types=1);

namespace Firefly\Admin\Tests\Support;

use Firefly\Observability\ObservabilityServiceProvider;
use Firefly\Observability\ObservabilityWiringProvider;
use Illuminate\Foundation\Application;
use Illuminate\Routing\Router;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;

/**
 * The dashboard over a real observability stack with tracing on, so the HTTP traffic page has rows to render
 * and those rows carry a trace id. The dashboard's own path is excluded from recording, as the config
 * reference recommends, so the page never lists the request that rendered it.
 */
abstract class ObservabilityAdminTestCase extends AdminCapstoneTestCase
{
    protected function fireflyProviders(): array
    {
        return [...parent::fireflyProviders(), ObservabilityServiceProvider::class, ObservabilityWiringProvider::class];
    }

    /** @return array<string, mixed> */
    protected function configOverrides(): array
    {
        return [
            ...parent::configOverrides(),
            'firefly.observability.tracing.enabled' => true,
            'firefly.observability.httpexchanges.exclude' => ['actuator', 'actuator/*', 'firefly', 'firefly/*'],
        ];
    }

    /**
     * The parameter stays untyped for the reason ObservabilityCapstoneTestCase::defineRoutes() gives: Testbench
     * declares it untyped, and narrowing a parameter in an override is an LSP violation PHP rejects.
     *
     * @param  Router  $router
     */
    protected function defineRoutes($router): void
    {
        $router->get('/demo/{id}', static fn (string $id): string => 'demo-'.$id);
    }

    protected function defineFireflyEnvironment(Application $app): void
    {
        parent::defineFireflyEnvironment($app);

        $app->instance(SpanExporterInterface::class, new InMemoryExporter);
    }
}
