<?php

declare(strict_types=1);

namespace Firefly\Observability\Boot;

use Firefly\Context\Boot\BootContext;
use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\BootPhase;
use Firefly\Observability\Metrics\MeterRegistry;
use Firefly\Resilience\ResilienceRegistry;
use Throwable;

/**
 * Registers pull-based (supplier) gauges at boot when a MeterRegistry is bound: process/runtime memory, and one
 * resilience_circuit_breaker_state gauge per configured circuit breaker (state → closed=0/open=1/half_open=2). Only
 * touches Resilience when it is actually installed (bound() guard) — observability keeps its Resilience dependency
 * soft at runtime even though the Deptrac edge exists.
 */
final class MeterBindingsPass implements BootPass
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
        $container = $context->container;
        if (! $container->bound(MeterRegistry::class)) {
            return;
        }

        /** @var MeterRegistry $registry */
        $registry = $container->make(MeterRegistry::class);

        $registry->gauge('process_resident_memory_bytes', [], static fn (): float => (float) memory_get_usage(true));
        $registry->gauge('php_memory_peak_bytes', [], static fn (): float => (float) memory_get_peak_usage(true));

        if (! $container->bound(ResilienceRegistry::class)) {
            return;
        }

        /** @var ResilienceRegistry $resilience */
        $resilience = $container->make(ResilienceRegistry::class);
        /** @var array<string, mixed> $breakers */
        $breakers = (array) $context->config->get('firefly.resilience.circuit-breaker', []);

        foreach (array_keys($breakers) as $name) {
            $name = (string) $name;
            $registry->gauge('resilience_circuit_breaker_state', ['name' => $name], static function () use ($resilience, $name): float {
                try {
                    return match ($resilience->circuitBreaker($name)->state()) {
                        'open' => 1.0,
                        'half_open' => 2.0,
                        default => 0.0,
                    };
                } catch (Throwable) {
                    return 0.0;
                }
            });
        }
    }
}
