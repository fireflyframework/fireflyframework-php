<?php

declare(strict_types=1);

use Firefly\Admin\Tests\Support\DisabledAdminTestCase;

uses(DisabledAdminTestCase::class);

it('registers no route at all when disabled', function () {
    /** @var DisabledAdminTestCase $this */
    $this->get('/firefly')->assertStatus(404);
    $this->get('/firefly/env')->assertStatus(404);
});

it('leaves the actuator alone when the dashboard is off', function () {
    /** @var DisabledAdminTestCase $this */
    $this->getJson('/actuator/health')->assertStatus(200)->assertJsonPath('status', 'UP');
});
