<?php

declare(strict_types=1);

namespace Firefly\Orchestration;

use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\FireflyServiceProvider;

/**
 * The boot-pass half of firefly/orchestration. It cannot ride on OrchestrationServiceProvider (an
 * AutoConfiguration records candidacy only), so — exactly like SchedulingWiringProvider and
 * FeatureFlagsWiringProvider — this plain FireflyServiceProvider binds the compiled OrchestrationManifest,
 * contributes the registry, gateway, schedule and route passes, registers the console commands and loads the
 * database migration. Both providers are listed in extra.laravel.providers.
 */
final class OrchestrationWiringProvider extends FireflyServiceProvider
{
    /**
     * @return list<BootPass>
     */
    public function passes(): array
    {
        return [];
    }
}
