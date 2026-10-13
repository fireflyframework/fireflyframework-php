<?php

declare(strict_types=1);

namespace Firefly\Actuator\Tests\Support;

/** The skeleton's health settings with none of the FIREFLY_HEALTH_{CACHE,QUEUE,REDIS}_ENABLED variables set. */
abstract class SkeletonInfrastructureHealthDefaultCapstoneTestCase extends SkeletonInfrastructureHealthCapstoneTestCase
{
    protected function healthEnvironment(): array
    {
        return [];
    }
}
