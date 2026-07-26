<?php

declare(strict_types=1);

namespace Firefly\Actuator\Tests\Support;

/**
 * The "env explicitly exposed" sibling of ActuatorCapstoneTestCase (the eda/messaging EdaQueueCapstoneTestCase /
 * MessagingQueueCapstoneTestCase idiom): ExposureModel's include list is captured ONCE at boot (a singleton
 * #[Bean]), so proving "env becomes reachable once explicitly exposed" needs its OWN boot with `env` already in
 * firefly.management.endpoints.web.exposure.include from the start.
 */
class ActuatorEnvExposedCapstoneTestCase extends ActuatorCapstoneTestCase
{
    protected function exposureInclude(): string
    {
        return 'health,info,env';
    }
}
