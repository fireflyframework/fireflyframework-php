<?php

declare(strict_types=1);

namespace Firefly\Observability;

use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\FireflyServiceProvider;

/**
 * The boot-pass + default-binding half of firefly/observability (cannot ride on ObservabilityServiceProvider —
 * AutoConfiguration's final register() records candidacy only). Later tasks bind the framework infrastructure
 * collectors (MeterRegistry, the Prometheus/Micrometer-JSON exposition endpoints, the HTTP instrumentation filter)
 * behind bound() guards here — the exact WebServiceProvider idiom — and contribute the bean-scan/route BootPasses
 * via passes(). Both this and ObservabilityServiceProvider are in extra.laravel.providers.
 */
final class ObservabilityWiringProvider extends FireflyServiceProvider
{
    /**
     * @return list<BootPass>
     */
    public function passes(): array
    {
        return [];
    }
}
