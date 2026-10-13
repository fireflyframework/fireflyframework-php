<?php

declare(strict_types=1);

namespace Firefly\Orchestration;

use Firefly\AutoConfigure\AutoConfiguration;

/**
 * The discovered orchestration auto-configuration provider (extra.laravel.providers). Extending
 * AutoConfiguration means its final register() records candidacy ONLY — it binds nothing and touches no
 * kernel; the two compiled manifests it points at describe OrchestrationAutoConfiguration's #[Bean]s, the
 * actuator endpoint and the health indicator. The boot-pass half rides on OrchestrationWiringProvider,
 * because AutoConfiguration's final register() never consumes passes().
 */
final class OrchestrationServiceProvider extends AutoConfiguration
{
    protected function componentManifestPath(): string
    {
        return __DIR__.'/../cache/firefly-orchestration-components.php';
    }

    protected function contextManifestPath(): string
    {
        return __DIR__.'/../cache/firefly-orchestration-context.php';
    }
}
