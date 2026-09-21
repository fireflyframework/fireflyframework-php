<?php

declare(strict_types=1);

use Firefly\Actuator\Tests\Support\MissingDatabaseHealthCapstoneTestCase;

uses(MissingDatabaseHealthCapstoneTestCase::class);

it('reports the db DOWN and answers 503 when the sqlite file is missing', function () {
    /** @var MissingDatabaseHealthCapstoneTestCase $this */
    $this->getJson('/actuator/health')
        ->assertStatus(503)
        ->assertJsonPath('status', 'DOWN')
        ->assertJsonPath('components.db.status', 'DOWN')
        ->assertJsonPath('components.ping.status', 'UP');
});
