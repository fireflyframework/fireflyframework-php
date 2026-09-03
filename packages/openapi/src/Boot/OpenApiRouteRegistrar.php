<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Boot;

use Firefly\Context\Boot\BootContext;
use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\BootPhase;
use Firefly\OpenApi\OpenApiProperties;
use Firefly\OpenApi\Web\OpenApiSpecAction;
use Firefly\OpenApi\Web\OpenApiViewerAction;
use Illuminate\Routing\Router;

/**
 * Mounts the two OpenAPI routes on the illuminate Router — the ActuatorRouteRegistrar pattern, and for the
 * same reason it exists there: an ATTRIBUTE route cannot be configurable. `#[GetMapping('/openapi.json')]`
 * bakes its literal into a compiled RouteDescriptor at `firefly:cache` time, so an operator could never move
 * the spec off a path that collides with one of their own, and could never take it off a public surface
 * without deleting the package. Registering natively from a BootPass reads the path out of config at boot and
 * mounts exactly what the deployment asked for.
 *
 * PHASE AND ORDER. WiringPasses (1000) is the instance stage — the container is fully flushed, so
 * OpenApiProperties (a #[Bean], bound at FlushDefinitions/650) is resolvable, and M6's RouteWiringPass has
 * already mounted the application's own attribute routes. Order 60 puts this AFTER actuator's registrar (50)
 * purely so the two framework surfaces mount in a stable, readable order in `php artisan route:list`; neither
 * depends on the other, and their default paths cannot collide.
 *
 * ACTIONS ARE RESOLVED PER REQUEST, INSIDE THE CLOSURE, never captured at boot. Building an
 * OpenApiSpecAction here would freeze one OpenApiGenerator into the route for the process's lifetime, which
 * is exactly the shape that breaks under Octane when a later request's container is a different sandbox.
 * `$container->make(...)` inside the closure is what ActuatorRouteRegistrar does, and for the same reason.
 *
 * MASTER GATE. `firefly.openapi.enabled` (default true) is enforced HERE rather than on the beans, matching
 * `firefly.management.enabled` in the actuator: the generator and its collaborators are inert without routes,
 * so gating the ROUTES is the whole of the switch. Turning it off leaves the two paths genuinely unrouted, so
 * they 404 through the router's own NotFoundHttpException — which ProblemDetailsRenderer then renders as a
 * proper 404 problem+json for a JSON client, not as a 500.
 */
final class OpenApiRouteRegistrar implements BootPass
{
    public function phase(): BootPhase
    {
        return BootPhase::WiringPasses;
    }

    public function order(): int
    {
        return 60;
    }

    public function run(BootContext $context): void
    {
        $container = $context->container;

        /** @var OpenApiProperties $properties */
        $properties = $container->make(OpenApiProperties::class);

        if (! $properties->enabled) {
            return;
        }

        /** @var Router $router */
        $router = $container->make('router');

        $router->get($properties->specPath, static fn (): mixed => $container->make(OpenApiSpecAction::class)())
            ->name('firefly.openapi.spec');

        if (! $properties->viewerEnabled) {
            return;
        }

        $router->get($properties->viewerPath, static fn (): mixed => $container->make(OpenApiViewerAction::class)())
            ->name('firefly.openapi.viewer');
    }
}
