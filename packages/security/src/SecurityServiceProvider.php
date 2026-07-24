<?php

declare(strict_types=1);

namespace Firefly\Security;

use Firefly\AutoConfigure\AutoConfiguration;

/**
 * The discovered security auto-configuration provider (extra.laravel.providers). Extending AutoConfiguration means
 * its final register() records candidacy ONLY; the compiled manifests describe SecurityAutoConfiguration's gated
 * #[Bean]s + the #[Component] filters. The boot-pass half rides on SecurityWiringProvider (never here).
 */
final class SecurityServiceProvider extends AutoConfiguration
{
    protected function componentManifestPath(): string
    {
        return __DIR__.'/../cache/firefly-security-components.php';
    }

    protected function contextManifestPath(): string
    {
        return __DIR__.'/../cache/firefly-security-context.php';
    }
}
