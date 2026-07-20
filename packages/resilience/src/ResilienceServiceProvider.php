<?php

declare(strict_types=1);

namespace Firefly\Resilience;

use Firefly\AutoConfigure\AutoConfiguration;

/**
 * The discovered resilience provider. Extending AutoConfiguration means its final register() records
 * candidacy ONLY — it binds nothing and touches no kernel; the #[Configuration] bean-source is the separate
 * ResilienceAutoConfiguration. The cache files are produced by firefly:cache (M15); they are committed here.
 */
final class ResilienceServiceProvider extends AutoConfiguration
{
    protected function componentManifestPath(): string
    {
        return __DIR__.'/../cache/firefly-resilience-components.php';
    }

    protected function contextManifestPath(): string
    {
        return __DIR__.'/../cache/firefly-resilience-context.php';
    }
}
