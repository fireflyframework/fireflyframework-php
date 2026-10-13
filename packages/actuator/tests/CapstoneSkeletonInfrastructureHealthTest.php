<?php

declare(strict_types=1);

use Firefly\Actuator\Tests\Support\SkeletonInfrastructureHealthEnabledCapstoneTestCase;

/*
 | The skeleton's cache and queue gates read FIREFLY_HEALTH_CACHE_ENABLED and FIREFLY_HEALTH_QUEUE_ENABLED, the
 | names the configuration table gives them: setting the variables, and nothing else, adds the components. The
 | redis gate's variable is left unset and the gate stays off, its documented default.
 */
uses(SkeletonInfrastructureHealthEnabledCapstoneTestCase::class);

it('turns the cache and queue indicators on from their environment variables', function () {
    /** @var SkeletonInfrastructureHealthEnabledCapstoneTestCase $this */
    $this->getJson('/actuator/health')
        ->assertStatus(200)
        ->assertJsonPath('components.cache.status', 'UP')
        ->assertJsonPath('components.cache.details.stores.array.status', 'UP')
        ->assertJsonPath('components.queue.status', 'UP')
        ->assertJsonPath('components.queue.details.connections.sync.size', 0)
        ->assertJsonMissingPath('components.redis');
});
