<?php

declare(strict_types=1);

namespace Firefly\Scheduling\Database;

use Firefly\AutoConfigure\AutoConfiguration;

/**
 * The discovered scheduling-database auto-configuration provider (extra.laravel.providers). Extending
 * AutoConfiguration means its final register() records candidacy ONLY — it binds nothing and touches no kernel; the
 * two compiled manifests it points at describe SchedulingDatabaseAutoConfiguration's beans and their
 * #[ConditionalOnProperty] gates.
 */
final class SchedulingDatabaseServiceProvider extends AutoConfiguration
{
    protected function componentManifestPath(): string
    {
        return __DIR__.'/../cache/firefly-scheduling-database-components.php';
    }

    protected function contextManifestPath(): string
    {
        return __DIR__.'/../cache/firefly-scheduling-database-context.php';
    }
}
