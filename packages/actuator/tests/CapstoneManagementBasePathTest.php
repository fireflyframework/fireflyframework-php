<?php

declare(strict_types=1);

use Firefly\Actuator\Tests\Support\ManagementBasePathCapstoneTestCase;

/**
 * firefly.management.server.base-path composes with firefly.management.endpoints.web.base-path rather than
 * replacing it, and — unlike Spring — applies with or without a management port, so one config file yields the same
 * actuator URL in development and production. See ManagementServerSettings::mountPath() for that argument in full.
 */
uses(ManagementBasePathCapstoneTestCase::class);

it('serves the actuator under the management server base path', function () {
    /** @var ManagementBasePathCapstoneTestCase $this */
    $this->getJson('/manage/actuator/health')->assertStatus(200)->assertJsonPath('status', 'UP');
});

it('no longer serves the un-prefixed path', function () {
    /** @var ManagementBasePathCapstoneTestCase $this */
    $this->getJson('/actuator/health')->assertStatus(404);
});

// A HAL index advertising /actuator/health while the router only answers /manage/actuator/health would hand every
// discovery client a set of dead links — the one failure an index exists to prevent.
it('advertises the prefixed hrefs from the HAL index', function () {
    /** @var ManagementBasePathCapstoneTestCase $this */
    $this->getJson('/manage/actuator')
        ->assertStatus(200)
        ->assertJsonPath('_links.self.href', 'http://localhost/manage/actuator')
        ->assertJsonPath('_links.health.href', 'http://localhost/manage/actuator/health');
});
