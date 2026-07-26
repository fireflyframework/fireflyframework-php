<?php

declare(strict_types=1);

namespace Firefly\Actuator\Health;

use Firefly\Container\Attributes\Component;

/** The trivial liveness probe — always UP. Discovered by HealthContributorRegistrar under the name 'ping'. */
#[Component]
final class PingHealthIndicator implements HealthIndicator
{
    public function health(): Health
    {
        return Health::up();
    }
}
