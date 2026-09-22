<?php

declare(strict_types=1);

namespace Firefly\Actuator\Tests\Support;

/**
 * The actuator capstone WITHOUT the explicit health.db.enabled override: what a created application gets. The
 * sqlite :memory: `testing` connection is the configured database, so the indicator registers itself and
 * reports UP. show-details is on so the component list is visible to the assertions.
 */
abstract class DefaultDbHealthCapstoneTestCase extends ActuatorCapstoneTestCase
{
    /** @return array<string, mixed> */
    protected function configOverrides(): array
    {
        return [
            'firefly.management.enabled' => true,
            'firefly.management.endpoint.health.show-details' => 'always',
            'firefly.management.endpoints.web.exposure.include' => 'health,info',
        ];
    }
}
