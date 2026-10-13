<?php

declare(strict_types=1);

namespace Firefly\Actuator\Tests\Support;

/** The skeleton's health settings with FIREFLY_HEALTH_CACHE_ENABLED and FIREFLY_HEALTH_QUEUE_ENABLED set to true. */
abstract class SkeletonInfrastructureHealthEnabledCapstoneTestCase extends SkeletonInfrastructureHealthCapstoneTestCase
{
    protected function healthEnvironment(): array
    {
        return [
            'FIREFLY_HEALTH_CACHE_ENABLED' => 'true',
            'FIREFLY_HEALTH_QUEUE_ENABLED' => 'true',
        ];
    }
}
