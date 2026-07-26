<?php

declare(strict_types=1);

namespace Firefly\Actuator\Tests\Support;

/**
 * The master-gate-OFF sibling of ActuatorCapstoneTestCase (the eda/messaging EdaQueueCapstoneTestCase /
 * MessagingQueueCapstoneTestCase idiom): firefly.management.enabled is read by ActuatorRouteRegistrar at
 * BootPhase::WiringPasses, so proving "no route when the master gate is off" needs its OWN boot with the flag
 * off from the start — flipping it inside a test body cannot un-mount a route the router already has.
 */
class ActuatorDisabledCapstoneTestCase extends ActuatorCapstoneTestCase
{
    protected function managementEnabled(): bool
    {
        return false;
    }
}
