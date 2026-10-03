<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Gating;

use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;

/**
 * A passed middleware decision belongs to the request, rather than this service instance.
 * Long-lived workers must bind the current request before dispatching a controller.
 */
final class RouteGateDecisions
{
    public const string ATTRIBUTE = 'firefly.feature-flags.gated';

    public function __construct(private readonly Container $app) {}

    public function record(Request $request, string $key, ?string $variant): void
    {
        $passed = $request->attributes->get(self::ATTRIBUTE);
        $passed = is_array($passed) ? $passed : [];
        $passed[$key.'|'.($variant ?? '')] = true;
        $request->attributes->set(self::ATTRIBUTE, $passed);
    }

    public function passed(string $key, ?string $variant): bool
    {
        if (! $this->app->bound('request')) {
            return false;
        }

        $request = $this->app->make('request');
        $passed = $request->attributes->get(self::ATTRIBUTE);

        return is_array($passed) && isset($passed[$key.'|'.($variant ?? '')]);
    }
}
