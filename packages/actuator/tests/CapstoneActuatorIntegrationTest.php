<?php

declare(strict_types=1);

use Firefly\Actuator\Tests\Support\ActuatorCapstoneTestCase;
use Firefly\Kernel\Version;

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

// /actuator/info used to answer `{}` on a skeleton — a 200 with nothing in it, which the admin dashboard
// could only render as an apology telling the operator to configure something. RuntimeInfoContributor is
// registered by default now, so the endpoint says what is actually running WITHOUT the application having
// authored firefly.management.info.app.* or shipped a firefly-build.json. The `{}`-not-`[]` rendering rule
// that used to be asserted here still is, over a boot that deliberately switches the contributor off:
// CapstoneActuatorEmptyInfoTest.
it('serves runtime facts at /actuator/info with no application configuration', function () {
    /** @var ActuatorCapstoneTestCase $this */
    $this->getJson('/actuator/info')
        ->assertStatus(200)
        ->assertJsonPath('runtime.php.version', PHP_VERSION)
        ->assertJsonPath('runtime.php.sapi', PHP_SAPI)
        ->assertJsonPath('runtime.firefly.version', Version::VERSION)
        ->assertJsonPath('runtime.php.opcache', fn (mixed $v): bool => is_bool($v))
        ->assertJsonPath('runtime.laravel.version', fn (mixed $v): bool => is_string($v) && $v !== '')
        ->assertJsonPath('runtime.memory.used', fn (mixed $v): bool => is_int($v) && $v > 0)
        ->assertJsonPath('runtime.memory.peak', fn (mixed $v): bool => is_int($v) && $v > 0);
});
