<?php

declare(strict_types=1);

namespace Firefly\Callbacks;

use Firefly\AutoConfigure\AutoConfiguration;

/** The discovered callbacks provider; the event subscription, migration and commands ride on CallbacksWiringProvider. */
final class CallbacksServiceProvider extends AutoConfiguration
{
    protected function componentManifestPath(): string
    {
        return __DIR__.'/../cache/firefly-callbacks-components.php';
    }

    protected function contextManifestPath(): string
    {
        return __DIR__.'/../cache/firefly-callbacks-context.php';
    }
}
