<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Gating;

use Firefly\Context\Boot\BootContext;
use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\BootPhase;
use Firefly\Data\Proxy\ProxyPlan;
use Firefly\Web\Route\RouteManifest;
use Illuminate\Routing\Router;

/** Attaches compiled method gates to attribute routes after RouteWiringPass. */
final class FeatureFlagRouteGatingPass implements BootPass
{
    public function phase(): BootPhase
    {
        return BootPhase::WiringPasses;
    }

    public function order(): int
    {
        return 10;
    }

    public function run(BootContext $context): void
    {
        $container = $context->container;
        /** @var Router $router */
        $router = $container->make('router');
        $router->aliasMiddleware(FeatureFlagGate::MIDDLEWARE_ALIAS, FeatureFlagMiddleware::class);

        if (! $container->bound(ProxyPlan::class) || ! $container->bound(RouteManifest::class)) {
            return;
        }

        $rules = [];
        $plan = $container->make(ProxyPlan::class);
        foreach ($plan->classes() as $class) {
            foreach ($plan->methodsFor($class) as $method => $rows) {
                foreach ($rows as $row) {
                    if ($row['advice'] !== FeatureFlagAdviceSource::ID || ($row['row']['route'] ?? false) !== true || ($row['row']['fallback'] ?? null) !== null) {
                        continue;
                    }

                    /** @var array{class: string, method: string, key: string, variant?: string|null, default?: bool, fallback?: string|null, route?: bool} $data */
                    $data = $row['row'];
                    $rules[$class.'::'.$method] = FeatureFlagMethodDescriptor::fromArray($data);
                }
            }
        }

        if ($rules === []) {
            return;
        }

        $decisions = $container->make(RouteGateDecisions::class);
        $routes = [];
        foreach ($router->getRoutes()->getRoutes() as $route) {
            if ($route->getDomain() !== null) {
                continue;
            }
            foreach ($route->methods() as $verb) {
                if (! is_string($verb)) {
                    continue;
                }

                $routes[$verb.' '.$route->uri()] = $route;
            }
        }

        foreach ($container->make(RouteManifest::class)->all() as $descriptor) {
            $rule = $rules[$descriptor->controllerClass.'::'.$descriptor->methodName] ?? null;
            $uri = trim($descriptor->path, '/');
            $route = $routes[strtoupper($descriptor->httpMethod).' '.($uri === '' ? '/' : $uri)] ?? null;

            if ($rule !== null && $route !== null) {
                $decisions->register($route, $rule);
                $route->middleware($rule->middleware());
            }
        }
    }
}
