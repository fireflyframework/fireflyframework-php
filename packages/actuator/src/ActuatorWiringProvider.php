<?php

declare(strict_types=1);

namespace Firefly\Actuator;

use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\FireflyServiceProvider;

/**
 * The boot-pass + default-binding half of firefly/actuator (cannot ride on ActuatorServiceProvider —
 * AutoConfiguration's final register() records candidacy only). Later tasks bind the framework infrastructure
 * collectors (ActuatorRegistry, HealthContributorRegistry, InfoContributorRegistry, StatusAggregator) behind
 * bound() guards here — the exact WebServiceProvider idiom — and contribute the bean-scan/route BootPasses via
 * passes(). Both this and ActuatorServiceProvider are in extra.laravel.providers.
 */
final class ActuatorWiringProvider extends FireflyServiceProvider
{
    /**
     * @return list<BootPass>
     */
    public function passes(): array
    {
        return [];
    }
}
