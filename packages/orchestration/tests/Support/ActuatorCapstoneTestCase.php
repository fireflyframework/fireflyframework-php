<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Tests\Support;

use Firefly\Actuator\ActuatorServiceProvider;
use Firefly\Actuator\ActuatorWiringProvider;
use Firefly\Validation\ValidationServiceProvider;
use Firefly\Web\WebServiceProvider;

/** The orchestration capstone with the management surface on: `/actuator/orchestration` and the health component. */
abstract class ActuatorCapstoneTestCase extends OrchestrationCapstoneTestCase
{
    /** @return list<class-string> */
    protected function fireflyProviders(): array
    {
        return [ValidationServiceProvider::class, WebServiceProvider::class, ActuatorServiceProvider::class, ActuatorWiringProvider::class, ...parent::fireflyProviders()];
    }

    /** @return array<string, mixed> */
    protected function configOverrides(): array
    {
        return [
            ...parent::configOverrides(),
            'firefly.management.enabled' => true,
            'firefly.management.endpoints.web.exposure.include' => 'health,orchestration',
            'firefly.management.endpoint.health.show-details' => 'always',
        ];
    }
}
