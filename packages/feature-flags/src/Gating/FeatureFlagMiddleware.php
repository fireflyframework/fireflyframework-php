<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Gating;

use Closure;
use Illuminate\Http\Request;

/** Evaluates a route gate before controller argument binding. */
final class FeatureFlagMiddleware
{
    public function __construct(
        private readonly FeatureFlagGate $gate,
        private readonly RouteGateDecisions $decisions,
    ) {}

    public function handle(Request $request, Closure $next, string $key, string $variant = '', string $default = 'false'): mixed
    {
        $variantName = $variant === '' ? null : $variant;

        $defaultEnabled = $default === 'true';
        if (! $this->gate->allows($key, $variantName, $defaultEnabled)) {
            throw $this->gate->disabled($key);
        }

        $this->decisions->record($request, $key, $variantName, $defaultEnabled);

        return $next($request);
    }
}
