<?php

declare(strict_types=1);

namespace Firefly\EventSourcing;

use Firefly\AutoConfigure\AutoConfiguration;

/**
 * The discovered event-sourcing auto-configuration provider (extra.laravel.providers). Extending
 * AutoConfiguration means its final register() records candidacy ONLY: the two compiled manifests it points at
 * describe EventSourcingAutoConfiguration's #[Bean]s, the orchestration bean and the health indicator. The
 * boot half (the scanned manifest, the commands, the migrations) rides on EventSourcingWiringProvider.
 */
final class EventSourcingServiceProvider extends AutoConfiguration
{
    protected function componentManifestPath(): string
    {
        return __DIR__.'/../cache/firefly-eventsourcing-components.php';
    }

    protected function contextManifestPath(): string
    {
        return __DIR__.'/../cache/firefly-eventsourcing-context.php';
    }
}
