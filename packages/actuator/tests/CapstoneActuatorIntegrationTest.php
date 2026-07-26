<?php

declare(strict_types=1);

use Firefly\Actuator\Tests\Support\ActuatorCapstoneTestCase;

uses(ActuatorCapstoneTestCase::class);

it('serves GET /actuator/health as UP 200', function () {
    /** @var ActuatorCapstoneTestCase $this */
    $this->getJson('/actuator/health')
        ->assertStatus(200)
        ->assertJsonPath('status', 'UP');
});

it('serves the HAL index at /actuator listing only exposed endpoints', function () {
    /** @var ActuatorCapstoneTestCase $this */
    $response = $this->getJson('/actuator');

    $response->assertStatus(200)
        ->assertJsonPath('_links.health.href', fn (mixed $href): bool => is_string($href) && str_ends_with($href, '/actuator/health'))
        ->assertJsonPath('_links.info.href', fn (mixed $href): bool => is_string($href) && str_ends_with($href, '/actuator/info'));

    expect($response->json('_links'))->not->toHaveKey('env'); // unexposed → not advertised
});

it('404s an unexposed sensitive endpoint (secure-by-default)', function () {
    /** @var ActuatorCapstoneTestCase $this */
    $this->getJson('/actuator/env')->assertStatus(404);
});

// The "exposes env only when explicitly included" case moved to CapstoneActuatorExposureOverrideTest.php: it
// needs its OWN boot (see ActuatorEnvExposedCapstoneTestCase) — ExposureModel's include list is a singleton
// #[Bean] captured ONCE at boot, so a post-boot config()->set() here (the brief's literal draft) never reaches
// the already-resolved instance. Fixed test, not production code — see ActuatorCapstoneTestCase::exposureInclude().
