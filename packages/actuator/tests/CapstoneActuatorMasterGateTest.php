<?php

declare(strict_types=1);

use Firefly\Actuator\Tests\Support\ActuatorDisabledCapstoneTestCase;

uses(ActuatorDisabledCapstoneTestCase::class);

/**
 * Carried requirement (T10 brief §"CRITICAL"): the master gate firefly.management.enabled=false must leave
 * /actuator/* completely unrouted, at the HTTP level — not merely at the BootPass unit level already covered by
 * ActuatorRouteRegistrarTest.
 */
it('routes nothing under /actuator when the master gate is off', function () {
    /** @var ActuatorDisabledCapstoneTestCase $this */
    $this->getJson('/actuator/health')->assertStatus(404);
    $this->getJson('/actuator')->assertStatus(404);
});
