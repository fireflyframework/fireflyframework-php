<?php

declare(strict_types=1);

namespace Firefly\Cqrs;

use Firefly\AutoConfigure\AutoConfiguration;

/**
 * The discovered cqrs auto-configuration provider (extra.laravel.providers). Extending AutoConfiguration means its
 * final register() records candidacy ONLY — it binds nothing and touches no kernel. In Task 1 the two compiled
 * manifests it points at are EMPTY (return []); Task 13 introduces CqrsAutoConfiguration and regenerates these
 * manifests to describe it. The boot-pass half rides on the SEPARATE CqrsWiringProvider (Task 7) — never here —
 * because AutoConfiguration's final register() never consumes passes(). Mirrors EdaServiceProvider / SchedulingServiceProvider.
 */
final class CqrsServiceProvider extends AutoConfiguration
{
    protected function componentManifestPath(): string
    {
        return __DIR__.'/../cache/firefly-cqrs-components.php';
    }

    protected function contextManifestPath(): string
    {
        return __DIR__.'/../cache/firefly-cqrs-context.php';
    }
}
