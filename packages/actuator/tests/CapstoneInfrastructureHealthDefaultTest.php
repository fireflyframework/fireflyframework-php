<?php

declare(strict_types=1);

use Firefly\Actuator\Tests\Support\DefaultDbHealthCapstoneTestCase;

uses(DefaultDbHealthCapstoneTestCase::class);

it('adds no cache, queue or redis component unless asked', function () {
    /** @var DefaultDbHealthCapstoneTestCase $this */
    $this->getJson('/actuator/health')
        ->assertStatus(200)
        ->assertJsonMissingPath('components.cache')
        ->assertJsonMissingPath('components.queue')
        ->assertJsonMissingPath('components.redis');
});
