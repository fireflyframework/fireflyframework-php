<?php

declare(strict_types=1);

namespace Firefly\Actuator\Tests\Fixtures;

/** A backed enum property, so the /configprops renderer is pinned to publishing the BACKING VALUE. */
enum DemoMode: string
{
    case Strict = 'strict';
    case Lenient = 'lenient';
}
