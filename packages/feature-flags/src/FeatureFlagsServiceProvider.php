<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags;

use Firefly\AutoConfigure\AutoConfiguration;

/**
 * The discovered feature-flags provider. Extending AutoConfiguration means its final register() records
 * candidacy ONLY; the #[Configuration] classes this package ships are described by the committed manifests.
 * The boot-pass half rides on FeatureFlagsWiringProvider.
 */
final class FeatureFlagsServiceProvider extends AutoConfiguration
{
    protected function componentManifestPath(): string
    {
        return __DIR__.'/../cache/firefly-feature-flags-components.php';
    }

    protected function contextManifestPath(): string
    {
        return __DIR__.'/../cache/firefly-feature-flags-context.php';
    }
}
