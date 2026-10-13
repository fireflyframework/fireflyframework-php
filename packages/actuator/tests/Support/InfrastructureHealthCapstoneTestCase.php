<?php

declare(strict_types=1);

namespace Firefly\Actuator\Tests\Support;

/** The actuator over HTTP with the cache and queue indicators switched ON (array cache, sync queue). */
abstract class InfrastructureHealthCapstoneTestCase extends ActuatorCapstoneTestCase
{
    /** @return array<string, mixed> */
    protected function configOverrides(): array
    {
        return [
            'firefly.management.enabled' => true,
            'firefly.management.endpoint.health.show-details' => 'always',
            'firefly.management.endpoints.web.exposure.include' => 'health,info',
            'firefly.management.endpoint.health.cache.enabled' => true,
            'firefly.management.endpoint.health.queue.enabled' => true,
            'cache.default' => 'array',
            'queue.default' => 'sync',
        ];
    }
}
