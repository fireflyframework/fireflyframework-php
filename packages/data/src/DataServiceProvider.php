<?php

declare(strict_types=1);

namespace Firefly\Data;

use Firefly\AutoConfigure\AutoConfiguration;

/**
 * The discovered data provider (extra.laravel.providers). Extending AutoConfiguration means its final register()
 * records candidacy ONLY — it binds nothing and touches no kernel; the #[Configuration] bean-source is the
 * separate DataAutoConfiguration (Task 8). The cache files are produced by firefly:cache (M15) and committed
 * here; they are empty in Task 2 (nothing to describe yet) and populated in Task 8.
 */
final class DataServiceProvider extends AutoConfiguration
{
    protected function componentManifestPath(): string
    {
        return __DIR__.'/../cache/firefly-data-components.php';
    }

    protected function contextManifestPath(): string
    {
        return __DIR__.'/../cache/firefly-data-context.php';
    }
}
