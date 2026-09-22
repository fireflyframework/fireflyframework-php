<?php

declare(strict_types=1);

use Firefly\Actuator\Tests\Support\DefaultDbHealthCapstoneTestCase;

uses(DefaultDbHealthCapstoneTestCase::class);

it('registers the db indicator by default and answers 200 UP against the configured sqlite database', function () {
    /** @var DefaultDbHealthCapstoneTestCase $this */
    $this->getJson('/actuator/health')
        ->assertStatus(200)
        ->assertJsonPath('status', 'UP')
        ->assertJsonPath('components.db.status', 'UP')
        ->assertJsonPath('components.db.details.database', 'sqlite');
});
