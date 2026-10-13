<?php

declare(strict_types=1);

use Firefly\Actuator\Tests\Support\BrokenCacheHealthCapstoneTestCase;

uses(BrokenCacheHealthCapstoneTestCase::class);

it('answers 503 with the failing store named when one cache store cannot be written', function () {
    /** @var BrokenCacheHealthCapstoneTestCase $this */
    $this->getJson('/actuator/health')
        ->assertStatus(503)
        ->assertJsonPath('components.cache.status', 'DOWN')
        ->assertJsonPath('components.cache.details.stores.array.status', 'UP')
        ->assertJsonPath('components.cache.details.stores.broken.status', 'DOWN');
});
