<?php

declare(strict_types=1);

use Firefly\Actuator\Tests\Support\NoDatabaseHealthCapstoneTestCase;

uses(NoDatabaseHealthCapstoneTestCase::class);

it('registers no db indicator at all and stays 200 UP when no database is configured', function () {
    /** @var NoDatabaseHealthCapstoneTestCase $this */
    $this->getJson('/actuator/health')
        ->assertStatus(200)
        ->assertJsonPath('status', 'UP')
        ->assertJsonMissingPath('components.db')
        ->assertJsonPath('components.ping.status', 'UP');
});
