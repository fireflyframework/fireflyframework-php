<?php

declare(strict_types=1);

namespace Firefly\Actuator\Tests\Support;

/** Probes the reachable connection AND one on a closed port: the component must go DOWN, naming the dead one. */
abstract class UnreachableRedisHealthCapstoneTestCase extends RedisHealthCapstoneTestCase
{
    /** @return list<string> */
    protected function redisConnections(): array
    {
        return ['default', 'unreachable'];
    }
}
