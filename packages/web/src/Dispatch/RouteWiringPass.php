<?php

declare(strict_types=1);

namespace Firefly\Web\Dispatch;

use Firefly\Context\Boot\BootContext;
use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\BootPhase;
use Firefly\Web\Route\RouteManifest;
use Illuminate\Routing\Router;

/**
 * Registers a native Laravel route per RouteDescriptor at phase WiringPasses (1000) — the instance stage,
 * fired from app.booted() during kernel bootstrap, BEFORE $router->dispatch(). Native routes reuse Laravel's
 * Router (route:list, URL generation, caching hooks) and run dispatch inside the HTTP-kernel middleware
 * pipeline. Resolves the manifest + dispatcher FROM the container at run() time, so their deps are fully
 * wired (never constructed early in the provider's passes()).
 */
final class RouteWiringPass implements BootPass
{
    public function phase(): BootPhase
    {
        return BootPhase::WiringPasses;
    }

    public function order(): int
    {
        return 0;
    }

    public function run(BootContext $context): void
    {
        $manifest = $context->container->make(RouteManifest::class);
        $dispatcher = $context->container->make(ControllerDispatcher::class);
        /** @var Router $router */
        $router = $context->container->make('router');

        foreach ($manifest->all() as $descriptor) {
            $route = $router->match([$descriptor->httpMethod], $descriptor->path, $dispatcher->actionFor($descriptor));
            if ($descriptor->name !== null) {
                $route->name($descriptor->name);
            }
        }
    }
}
