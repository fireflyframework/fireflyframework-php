<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Gating;

use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use WeakMap;

/**
 * A passed middleware decision belongs to the request, rather than this service instance.
 * Long-lived workers must bind the current request before dispatching a controller.
 */
final class RouteGateDecisions
{
    public const string ATTRIBUTE = 'firefly.feature-flags.gated';

    /** @var WeakMap<Route, FeatureFlagMethodDescriptor> */
    private WeakMap $routes;

    public function __construct(private readonly Container $app)
    {
        $this->routes = new WeakMap;
    }

    public function register(Route $route, FeatureFlagMethodDescriptor $rule): void
    {
        $this->routes[$route] = $rule;
    }

    public function record(Request $request, string $key, ?string $variant, bool $default): void
    {
        $route = $request->route();
        if (! $route instanceof Route) {
            return;
        }

        $rule = $this->routes[$route] ?? null;
        if ($rule !== null && $rule->key === $key && $rule->variant === $variant && $rule->default === $default) {
            $request->attributes->set(self::ATTRIBUTE, $rule);
        }
    }

    public function passed(FeatureFlagMethodDescriptor $rule): bool
    {
        if (! $this->app->bound('request')) {
            return false;
        }

        $request = $this->app->make('request');
        $route = $request->route();
        if (! $route instanceof Route) {
            return false;
        }

        $routedRule = $this->routes[$route] ?? null;

        return $routedRule !== null
            && $request->attributes->get(self::ATTRIBUTE) === $routedRule
            && $routedRule->toArray() === $rule->toArray();
    }
}
