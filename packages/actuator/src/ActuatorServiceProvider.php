<?php

declare(strict_types=1);

namespace Firefly\Actuator;

use Firefly\AutoConfigure\AutoConfiguration;

/**
 * The discovered actuator auto-configuration provider (extra.laravel.providers). Extending AutoConfiguration means
 * its final register() records candidacy ONLY; the compiled manifests describe ActuatorAutoConfiguration's #[Bean]s
 * plus the #[Component] endpoints/indicators. The boot-pass half rides on ActuatorWiringProvider (never here).
 * In Task 1 both manifests are EMPTY (return []); later tasks regenerate them as endpoints/indicators land.
 */
final class ActuatorServiceProvider extends AutoConfiguration
{
    protected function componentManifestPath(): string
    {
        return __DIR__.'/../cache/firefly-actuator-components.php';
    }

    protected function contextManifestPath(): string
    {
        return __DIR__.'/../cache/firefly-actuator-context.php';
    }
}
