<?php

declare(strict_types=1);

namespace Firefly\Eda;

use Firefly\AutoConfigure\AutoConfiguration;

/**
 * The discovered eda auto-configuration provider (extra.laravel.providers). Extending AutoConfiguration means its
 * final register() records candidacy ONLY — it binds nothing and touches no kernel. In Task 1 the two compiled
 * manifests it points at are EMPTY (return []); Task 8 introduces EdaAutoConfiguration and regenerates these
 * manifests to describe it. The boot-pass half rides on the SEPARATE EdaWiringProvider (Task 9) — never here —
 * because AutoConfiguration's final register() never consumes passes().
 */
final class EdaServiceProvider extends AutoConfiguration
{
    protected function componentManifestPath(): string
    {
        return __DIR__.'/../cache/firefly-eda-components.php';
    }

    protected function contextManifestPath(): string
    {
        return __DIR__.'/../cache/firefly-eda-context.php';
    }
}
