<?php

declare(strict_types=1);

use Firefly\Actuator\Tests\Support\UnreachableRedisHealthCapstoneTestCase;
use Firefly\Testing\Integration\IntegrationDsn;
use Predis\Client;

uses(UnreachableRedisHealthCapstoneTestCase::class);

it('answers 503 and names the connection Redis cannot reach', function () {
    /** @var UnreachableRedisHealthCapstoneTestCase $this */
    $this->getJson('/actuator/health')
        ->assertStatus(503)
        ->assertJsonPath('components.redis.status', 'DOWN')
        ->assertJsonPath('components.redis.details.connections.default.status', 'UP')
        ->assertJsonPath('components.redis.details.connections.unreachable.status', 'DOWN');
})->group('integration')->skip(IntegrationDsn::missing(IntegrationDsn::REDIS) || ! class_exists(Client::class), 'Set FIREFLY_REDIS_URL (and install predis/predis) to run the Redis health integration test.');
