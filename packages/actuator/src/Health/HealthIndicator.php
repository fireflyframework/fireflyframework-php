<?php

declare(strict_types=1);

namespace Firefly\Actuator\Health;

/**
 * The health SPI. A #[Component] HealthIndicator is discovered by HealthContributorRegistrar (a bean-scan pass
 * mirroring FilterChainRegistrar) and aggregated by HealthEndpoint. A thrown health() is caught and rendered DOWN
 * by the endpoint — never an unhandled 500 (fail-safe invariant).
 */
interface HealthIndicator
{
    public function health(): Health;
}
