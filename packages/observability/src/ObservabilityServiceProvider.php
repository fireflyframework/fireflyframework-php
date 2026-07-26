<?php

declare(strict_types=1);

namespace Firefly\Observability;

use Firefly\AutoConfigure\AutoConfiguration;

/**
 * The discovered observability auto-configuration provider (extra.laravel.providers). Extending AutoConfiguration
 * means its final register() records candidacy ONLY; the compiled manifests describe ObservabilityAutoConfiguration's
 * #[Bean]s plus the #[Component] meters/instrumentation. The boot-pass half rides on ObservabilityWiringProvider
 * (never here). In this task both manifests are EMPTY (return []); later tasks regenerate them as the MeterRegistry,
 * endpoints, and instrumentation land.
 */
final class ObservabilityServiceProvider extends AutoConfiguration
{
    protected function componentManifestPath(): string
    {
        return __DIR__.'/../cache/firefly-observability-components.php';
    }

    protected function contextManifestPath(): string
    {
        return __DIR__.'/../cache/firefly-observability-context.php';
    }
}
