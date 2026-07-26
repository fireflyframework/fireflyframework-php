<?php

declare(strict_types=1);

namespace Firefly\Actuator\Boot;

use Firefly\Actuator\Health\HealthContributorRegistry;
use Firefly\Actuator\Health\HealthIndicator;
use Firefly\Context\Boot\BootContext;
use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\BootPhase;

/**
 * Bean-scan pass (mirrors FilterChainRegistrar): discovers #[Component] HealthIndicator beans from the
 * condition-filtered definitions, resolves each from the container, and registers it under a derived name. Runs at
 * WiringPasses (instance stage) — ordering vs the route registrar is immaterial because HealthEndpoint reads the
 * populated registry at REQUEST time, not at construction.
 */
final class HealthContributorRegistrar implements BootPass
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
        /** @var HealthContributorRegistry $registry */
        $registry = $context->container->make(HealthContributorRegistry::class);

        foreach ($context->definitions->all() as $definition) {
            $class = $definition->class();
            if (! is_a($class, HealthIndicator::class, true)) {
                continue;
            }
            /** @var HealthIndicator $indicator */
            $indicator = $context->container->make($class);
            $registry->register(self::nameFor($class), $indicator);
        }
    }

    public static function nameFor(string $class): string
    {
        $short = ($pos = strrpos($class, '\\')) !== false ? substr($class, $pos + 1) : $class;

        foreach (['HealthIndicator', 'Indicator'] as $suffix) {
            if (str_ends_with($short, $suffix)) {
                $short = substr($short, 0, -strlen($suffix));
                break;
            }
        }

        return strtolower($short);
    }
}
