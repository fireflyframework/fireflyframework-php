<?php

declare(strict_types=1);

namespace Firefly\Actuator\Tests\Support;

/** The actuator capstone with one more id exposed: `capture`, an endpoint the test registers itself. */
abstract class RawBodyCapstoneTestCase extends ActuatorCapstoneTestCase
{
    protected function exposureInclude(): string
    {
        return 'health,info,capture';
    }
}
