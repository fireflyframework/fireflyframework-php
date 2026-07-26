<?php

declare(strict_types=1);

use Firefly\Actuator\Tests\Support\ActuatorEnvExposedCapstoneTestCase;

uses(ActuatorEnvExposedCapstoneTestCase::class);

/**
 * Carried requirement (T10 brief §"CRITICAL"): once a sensitive endpoint is explicitly added to
 * firefly.management.endpoints.web.exposure.include it must become reachable. This needs its OWN boot (see
 * ActuatorEnvExposedCapstoneTestCase) — ExposureModel is a singleton #[Bean] whose include list is captured
 * once at BootPhase::FlushDefinitions, not re-read live per request.
 */
it('exposes env only when explicitly included', function () {
    /** @var ActuatorEnvExposedCapstoneTestCase $this */
    $this->getJson('/actuator/env')->assertStatus(200)->assertJsonPath('firefly', fn ($v) => is_array($v));
});
