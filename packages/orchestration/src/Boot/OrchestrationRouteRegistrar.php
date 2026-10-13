<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Boot;

use Firefly\Context\Boot\BootContext;
use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\BootPhase;
use Firefly\Orchestration\Http\OrchestrationController;
use Firefly\Orchestration\OrchestrationSettings;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mounts the REST surface on the illuminate Router — the ActuatorRouteRegistrar pattern, because an
 * attribute route cannot follow `firefly.orchestration.rest.path`. Nothing is mounted while
 * `firefly.orchestration.rest.enabled` is false (the default), so the paths 404 through the router itself.
 * WiringPasses order 65: after actuator (50), feature-flags sync (55) and OpenAPI (60), so `route:list` stays
 * readable. The controller is resolved per request inside each closure, never captured at boot (Octane). Paths
 * are concatenated rather than interpolated, because ConfigReferenceTest reads an interpolated `->get(` argument
 * as a Config key spelled out of sight.
 */
final class OrchestrationRouteRegistrar implements BootPass
{
    public const string ROUTE_PREFIX = 'firefly.orchestration.';

    public function phase(): BootPhase
    {
        return BootPhase::WiringPasses;
    }

    public function order(): int
    {
        return 65;
    }

    public function run(BootContext $context): void
    {
        $container = $context->container;
        if (! $container->bound(OrchestrationSettings::class)) {
            return;
        }

        /** @var OrchestrationSettings $settings */
        $settings = $container->make(OrchestrationSettings::class);
        if (! $settings->rest->enabled) {
            return;
        }

        /** @var Router $router */
        $router = $container->make('router');
        $base = $settings->rest->path;
        $controller = static function () use ($container): OrchestrationController {
            /** @var OrchestrationController */
            return $container->make(OrchestrationController::class);
        };

        $router->post($base.'/sagas/{name}', static fn (Request $request, string $name): Response => $controller()->startSaga($request, $name))->name(self::ROUTE_PREFIX.'start-saga');
        $router->post($base.'/tcc/{name}', static fn (Request $request, string $name): Response => $controller()->startTcc($request, $name))->name(self::ROUTE_PREFIX.'start-tcc');
        $router->post($base.'/workflows/{id}', static fn (Request $request, string $id): Response => $controller()->startWorkflow($request, $id))->name(self::ROUTE_PREFIX.'start-workflow');
        $router->get($base.'/executions', static fn (Request $request): Response => $controller()->executions($request))->name(self::ROUTE_PREFIX.'executions');
        $router->get($base.'/executions/{id}', static fn (string $id): Response => $controller()->execution($id))->name(self::ROUTE_PREFIX.'execution');
        $router->post($base.'/workflows/{id}/signals/{signal}', static fn (Request $request, string $id, string $signal): Response => $controller()->signal($request, $id, $signal))->name(self::ROUTE_PREFIX.'signal');
        $router->post($base.'/workflows/{id}/suspend', static fn (Request $request, string $id): Response => $controller()->suspend($request, $id))->name(self::ROUTE_PREFIX.'suspend');
        $router->post($base.'/workflows/{id}/resume', static fn (string $id): Response => $controller()->resume($id))->name(self::ROUTE_PREFIX.'resume');
        $router->post($base.'/workflows/{id}/cancel', static fn (string $id): Response => $controller()->cancel($id))->name(self::ROUTE_PREFIX.'cancel');
        $router->get($base.'/workflows/{id}/queries/{query}', static fn (string $id, string $query): Response => $controller()->query($id, $query))->name(self::ROUTE_PREFIX.'query');
        $router->get($base.'/dlq', static fn (Request $request): Response => $controller()->deadLetters($request))->name(self::ROUTE_PREFIX.'dlq');
        $router->post($base.'/dlq/{id}/retry', static fn (string $id): Response => $controller()->retryDeadLetter($id))->name(self::ROUTE_PREFIX.'dlq-retry');
        $router->delete($base.'/dlq/{id}', static fn (string $id): Response => $controller()->deleteDeadLetter($id))->name(self::ROUTE_PREFIX.'dlq-delete');
    }
}
