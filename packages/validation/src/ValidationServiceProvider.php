<?php

declare(strict_types=1);

namespace Firefly\Validation;

use Firefly\AutoConfigure\AutoConfiguration;

/**
 * The discovered provider (extra.laravel.providers). Points the auto-configuration engine at
 * firefly/validation's compiled manifests, which describe ValidationAutoConfiguration. Extending
 * AutoConfiguration means its final register() records candidacy ONLY — it binds nothing, touches no kernel.
 * The #[Configuration] bean-source is the SEPARATE ValidationAutoConfiguration class (a #[Configuration] class
 * cannot also be a ServiceProvider). The cache files are produced by firefly/cli's `firefly:cache` (M15); in
 * M5 the make-or-break integration test compiles them to a temp path via a ManifestPathAutoConfiguration.
 */
final class ValidationServiceProvider extends AutoConfiguration
{
    protected function componentManifestPath(): string
    {
        return __DIR__.'/../cache/firefly-validation-components.php';
    }

    protected function contextManifestPath(): string
    {
        return __DIR__.'/../cache/firefly-validation-context.php';
    }
}
