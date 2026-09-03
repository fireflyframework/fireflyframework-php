<?php

declare(strict_types=1);

namespace Firefly\Actuator\Tests\Support;

/**
 * The actuator capstone with the runtime info contributor switched OFF, so /actuator/info genuinely has no
 * fragment to merge and the empty-body rendering rule can still be asserted at HTTP level.
 *
 * It needs its OWN boot rather than a config()->set() inside a test body, for the same reason
 * ActuatorDisabledCapstoneTestCase and ActuatorEnvExposedCapstoneTestCase do: firefly.management.info.runtime
 * .enabled is a #[ConditionalOnProperty] read by the condition pipeline while definitions are being filtered,
 * long before any test body runs. Flipping it afterwards would reach nothing — RuntimeInfoContributor would
 * already be registered in InfoContributorRegistry.
 */
abstract class EmptyInfoActuatorCapstoneTestCase extends ActuatorCapstoneTestCase
{
    /**
     * @return array<string, mixed>
     */
    protected function configOverrides(): array
    {
        return parent::configOverrides() + ['firefly.management.info.runtime.enabled' => false];
    }
}
