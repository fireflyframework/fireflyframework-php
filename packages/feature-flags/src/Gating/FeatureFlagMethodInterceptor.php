<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Gating;

use Firefly\Data\Proxy\MethodInterceptor;
use Firefly\Data\Proxy\MethodInvocation;
use WeakMap;

/** The feature flag link of a generated proxy's advice chain. */
final class FeatureFlagMethodInterceptor implements MethodInterceptor
{
    /** @var WeakMap<object, array<string, int>> */
    private WeakMap $fallbacks;

    public function __construct(
        private readonly FeatureFlagGate $gate,
        private readonly RouteGateDecisions $decisions,
    ) {
        $this->fallbacks = new WeakMap;
    }

    public function invoke(MethodInvocation $invocation): mixed
    {
        $rule = $invocation->descriptor(FeatureFlagMethodDescriptor::class);
        if ($rule === null) {
            return $invocation->proceed();
        }

        $receiver = $invocation->getThis();
        $method = strtolower($invocation->getMethod());
        if (($this->fallbacks[$receiver][$method] ?? 0) > 0) {
            return $invocation->proceed();
        }

        if ($rule->route && $rule->fallback === null && $this->decisions->passed($rule)) {
            return $invocation->proceed();
        }

        if ($this->gate->allows($rule->key, $rule->variant, $rule->default)) {
            return $invocation->proceed();
        }

        if ($rule->fallback === null) {
            throw $this->gate->disabled($rule->key);
        }

        $fallbackMethod = strtolower($rule->fallback);
        $active = $this->fallbacks[$receiver] ?? [];
        $active[$fallbackMethod] = ($active[$fallbackMethod] ?? 0) + 1;
        $this->fallbacks[$receiver] = $active;

        try {
            /** @var callable $fallback */
            $fallback = [$receiver, $rule->fallback];

            return $fallback(...$invocation->getArguments());
        } finally {
            $active = $this->fallbacks[$receiver];
            if (--$active[$fallbackMethod] === 0) {
                unset($active[$fallbackMethod]);
            }
            if ($active === []) {
                unset($this->fallbacks[$receiver]);
            } else {
                $this->fallbacks[$receiver] = $active;
            }
        }
    }
}
