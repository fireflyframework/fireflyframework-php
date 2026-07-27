<?php

declare(strict_types=1);

namespace Firefly\Eda\Rabbitmq;

use Firefly\AutoConfigure\AutoConfiguration;

/** Discovered auto-config provider: records candidacy only; points at the two compiled manifests. */
final class EdaRabbitmqServiceProvider extends AutoConfiguration
{
    protected function componentManifestPath(): string
    {
        return __DIR__.'/../cache/firefly-eda-rabbitmq-components.php';
    }

    protected function contextManifestPath(): string
    {
        return __DIR__.'/../cache/firefly-eda-rabbitmq-context.php';
    }
}
