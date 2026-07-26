<?php

declare(strict_types=1);

use Firefly\Actuator\Tests\Support\SecuredActuatorCapstoneTestCase;

uses(SecuredActuatorCapstoneTestCase::class);

it('locks /actuator/env to ACTUATOR while /actuator/health stays public', function () {
    /** @var SecuredActuatorCapstoneTestCase $this */
    $this->getJson('/actuator/health')->assertStatus(200); // permitAll

    $this->getJson('/actuator/env')->assertStatus(401); // anonymous → authentication required
});

it('masks sensitive /env values even when exposed', function () {
    /** @var SecuredActuatorCapstoneTestCase $this */
    config()->set('firefly.security.enabled', false);
    config()->set('firefly.security.http.enabled', false);
    config()->set('firefly.datasource.password', 'hunter2');

    $body = $this->getJson('/actuator/env')->assertStatus(200)->json();

    expect(json_encode($body))->not->toContain('hunter2')->toContain('******');
});
