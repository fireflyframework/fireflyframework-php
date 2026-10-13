<?php

declare(strict_types=1);

namespace Firefly\Eda\Database;

use Firefly\AutoConfigure\AutoConfiguration;

/**
 * The discovered eda-database auto-configuration provider (extra.laravel.providers). Extending AutoConfiguration
 * means its final register() records candidacy ONLY — it binds nothing and touches no kernel; the two compiled
 * manifests it points at describe DatabaseOutboxAutoConfiguration's beans and their #[ConditionalOnProperty] gates.
 */
final class EdaDatabaseServiceProvider extends AutoConfiguration
{
    protected function componentManifestPath(): string
    {
        return __DIR__.'/../cache/firefly-eda-database-components.php';
    }

    protected function contextManifestPath(): string
    {
        return __DIR__.'/../cache/firefly-eda-database-context.php';
    }
}
