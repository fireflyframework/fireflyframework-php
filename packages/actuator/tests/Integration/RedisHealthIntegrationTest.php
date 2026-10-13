<?php

declare(strict_types=1);

use Firefly\Actuator\Tests\Support\RedisHealthCapstoneTestCase;
use Firefly\Testing\Integration\IntegrationDsn;
use Predis\Client;

uses(RedisHealthCapstoneTestCase::class);

it('reports the shared Redis UP through PING', function () {
    /** @var RedisHealthCapstoneTestCase $this */
    $this->getJson('/actuator/health')
        ->assertStatus(200)
        ->assertJsonPath('components.redis.status', 'UP')
        ->assertJsonPath('components.redis.details.connections.default.status', 'UP');
})->group('integration')->skip(IntegrationDsn::missing(IntegrationDsn::REDIS) || ! class_exists(Client::class), 'Set FIREFLY_REDIS_URL (and install predis/predis) to run the Redis health integration test.');
