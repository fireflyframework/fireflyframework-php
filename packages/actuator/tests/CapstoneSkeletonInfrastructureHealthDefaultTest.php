<?php

declare(strict_types=1);

use Firefly\Actuator\Tests\Support\SkeletonInfrastructureHealthDefaultCapstoneTestCase;

/*
 | The skeleton's live cache, queue and redis keys default to off: an application that copies the reference and
 | sets none of FIREFLY_HEALTH_{CACHE,QUEUE,REDIS}_ENABLED keeps the /health it had before the upgrade.
 */
uses(SkeletonInfrastructureHealthDefaultCapstoneTestCase::class);

it('keeps the cache, queue and redis indicators off when their environment variables are unset', function () {
    /** @var SkeletonInfrastructureHealthDefaultCapstoneTestCase $this */
    $this->getJson('/actuator/health')
        ->assertStatus(200)
        ->assertJsonPath('components.db.status', 'UP')
        ->assertJsonMissingPath('components.cache')
        ->assertJsonMissingPath('components.queue')
        ->assertJsonMissingPath('components.redis');
});
