<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Tests\Support;

use Firefly\Actuator\ActuatorServiceProvider;
use Firefly\Actuator\ActuatorWiringProvider;
use Firefly\Validation\ValidationServiceProvider;
use Firefly\Web\WebServiceProvider;

/** The event-sourcing capstone with the management surface on: `/actuator/health` and its `eventsourcing` component. */
abstract class ActuatorEventSourcingCapstoneTestCase extends EventSourcingCapstoneTestCase
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
            'firefly.management.endpoints.web.exposure.include' => 'health',
            'firefly.management.endpoint.health.show-details' => 'always',
            'firefly.eventsourcing.projection.max-lag' => 2,
        ];
    }
}
