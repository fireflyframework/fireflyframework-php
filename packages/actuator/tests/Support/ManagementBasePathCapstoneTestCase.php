<?php

declare(strict_types=1);

namespace Firefly\Actuator\Tests\Support;

/**
 * The same full HTTP boot as ActuatorCapstoneTestCase, with firefly.management.server.base-path set and NO
 * management port — the combination Spring would silently ignore. Its own boot for the usual reason: the mount path
 * is read at BootPhase::WiringPasses and a post-boot config()->set() cannot move a route that is already on the
 * Router.
 */
abstract class ManagementBasePathCapstoneTestCase extends ActuatorCapstoneTestCase
{
    protected function configOverrides(): array
    {
        return parent::configOverrides() + [
            'firefly.management.server.base-path' => '/manage',
        ];
    }
}
