<?php

declare(strict_types=1);

use Firefly\Actuator\Tests\Support\InfrastructureHealthCapstoneTestCase;

uses(InfrastructureHealthCapstoneTestCase::class);

it('adds cache and queue components to /actuator/health, UP', function () {
    /** @var InfrastructureHealthCapstoneTestCase $this */
    $this->getJson('/actuator/health')
        ->assertStatus(200)
        ->assertJsonPath('components.cache.status', 'UP')
        ->assertJsonPath('components.cache.details.stores.array.status', 'UP')
        ->assertJsonPath('components.queue.status', 'UP')
        ->assertJsonPath('components.queue.details.connections.sync.size', 0)
        ->assertJsonMissingPath('components.redis');
});
