<?php

declare(strict_types=1);

namespace Firefly\Scheduling\Postgres;

use Firefly\AutoConfigure\AutoConfiguration;

/**
 * The discovered scheduling-postgres auto-configuration provider (extra.laravel.providers). Extending
 * AutoConfiguration means its final register() records candidacy ONLY — it binds nothing and touches no
 * kernel; the two compiled manifests it points at describe PgAdvisoryLockAutoConfiguration's #[Bean] and
 * its #[ConditionalOnProperty] gate.
 */
final class SchedulingPostgresServiceProvider extends AutoConfiguration
{
    protected function componentManifestPath(): string
    {
        return __DIR__.'/../cache/firefly-scheduling-postgres-components.php';
    }

    protected function contextManifestPath(): string
    {
        return __DIR__.'/../cache/firefly-scheduling-postgres-context.php';
    }
}
