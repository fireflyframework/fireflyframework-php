<?php

declare(strict_types=1);

namespace Firefly\Actuator\Boot;

use Firefly\Actuator\Info\InfoContributor;
use Firefly\Actuator\Info\InfoContributorRegistry;
use Firefly\Context\Boot\BootContext;
use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\BootPhase;

/** Bean-scan pass: discovers #[Component] InfoContributor beans and registers them (mirrors HealthContributorRegistrar). */
final class InfoContributorRegistrar implements BootPass
{
    public function phase(): BootPhase
    {
        return BootPhase::WiringPasses;
    }

    public function order(): int
    {
        return 20;
    }

    public function run(BootContext $context): void
    {
        /** @var InfoContributorRegistry $registry */
        $registry = $context->container->make(InfoContributorRegistry::class);

        foreach ($context->definitions->all() as $definition) {
            $class = $definition->class();
            if (! is_a($class, InfoContributor::class, true)) {
                continue;
            }
            /** @var InfoContributor $contributor */
            $contributor = $context->container->make($class);
            $registry->register($contributor);
        }
    }
}
