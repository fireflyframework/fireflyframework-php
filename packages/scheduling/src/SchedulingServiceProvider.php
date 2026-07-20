<?php

declare(strict_types=1);

namespace Firefly\Scheduling;

use Firefly\AutoConfigure\AutoConfiguration;

/**
 * The discovered scheduling auto-configuration provider (extra.laravel.providers). Extending AutoConfiguration
 * means its final register() records candidacy ONLY — it binds nothing and touches no kernel. In Task 8 the two
 * compiled manifests it points at are EMPTY (return []); Task 10 introduces SchedulingAutoConfiguration and
 * Task 11 regenerates these manifests to describe it (the DistributedLock bean). The boot-pass half of the
 * package rides on the SEPARATE SchedulingWiringProvider (Task 11) — never here — because AutoConfiguration's
 * final register() never consumes passes().
 */
final class SchedulingServiceProvider extends AutoConfiguration
{
    protected function componentManifestPath(): string
    {
        return __DIR__.'/../cache/firefly-scheduling-components.php';
    }

    protected function contextManifestPath(): string
    {
        return __DIR__.'/../cache/firefly-scheduling-context.php';
    }
}
