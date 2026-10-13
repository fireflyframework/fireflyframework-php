<?php

declare(strict_types=1);

namespace Firefly\Webhooks;

use Firefly\AutoConfigure\AutoConfiguration;

/**
 * The discovered webhooks provider. Extending AutoConfiguration means its final register() records candidacy ONLY;
 * the #[Configuration] class is described by the committed manifests. The route, the migration and the command
 * ride on WebhooksWiringProvider (the FeatureFlagsServiceProvider / FeatureFlagsWiringProvider split).
 */
final class WebhooksServiceProvider extends AutoConfiguration
{
    protected function componentManifestPath(): string
    {
        return __DIR__.'/../cache/firefly-webhooks-components.php';
    }

    protected function contextManifestPath(): string
    {
        return __DIR__.'/../cache/firefly-webhooks-context.php';
    }
}
