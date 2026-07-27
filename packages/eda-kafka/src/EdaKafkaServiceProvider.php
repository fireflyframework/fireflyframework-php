<?php

declare(strict_types=1);

namespace Firefly\Eda\Kafka;

use Firefly\AutoConfigure\AutoConfiguration;

/** Discovered auto-config provider: records candidacy only; points at the two compiled manifests. */
final class EdaKafkaServiceProvider extends AutoConfiguration
{
    protected function componentManifestPath(): string
    {
        return __DIR__.'/../cache/firefly-eda-kafka-components.php';
    }

    protected function contextManifestPath(): string
    {
        return __DIR__.'/../cache/firefly-eda-kafka-context.php';
    }
}
