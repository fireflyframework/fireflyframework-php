<?php

declare(strict_types=1);

namespace Firefly\Actuator\Tests\Support;

use Firefly\Testing\Integration\IntegrationDsn;

/** The redis indicator ON against the shared Redis named by FIREFLY_REDIS_URL, through predis. */
abstract class RedisHealthCapstoneTestCase extends ActuatorCapstoneTestCase
{
    /** @return array<string, mixed> */
    protected function configOverrides(): array
    {
        return [
            'firefly.management.enabled' => true,
            'firefly.management.endpoint.health.show-details' => 'always',
            'firefly.management.endpoints.web.exposure.include' => 'health,info',
            'firefly.management.endpoint.health.redis.enabled' => true,
            'firefly.management.endpoint.health.redis.connections' => $this->redisConnections(),
            'database.redis.client' => 'predis',
            'database.redis.default' => ['url' => (string) IntegrationDsn::value(IntegrationDsn::REDIS)],
            'database.redis.unreachable' => ['host' => '127.0.0.1', 'port' => 1, 'timeout' => 0.5, 'read_write_timeout' => 0.5],
        ];
    }

    /** @return list<string> */
    protected function redisConnections(): array
    {
        return ['default'];
    }
}
